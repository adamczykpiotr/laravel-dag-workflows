<?php

namespace AdamczykPiotr\DagWorkflows\Jobs;

use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Middlewares\ResolvableItems\WorkflowResolvableItemsMiddleware;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDefinitionParser;
use AdamczykPiotr\DagWorkflows\Services\WorkflowRepository;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

class ResolvableTaskResolverJob implements ShouldQueue {

    use HasWorkflowTracking, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    /**
     * @param string $name
     * @param string|array<int, string> $dependsOn
     * @param SerializableClosure $itemProvider
     * @param SerializableClosure $jobProvider
     */
    public function __construct(
        protected string $name,
        protected string|array $dependsOn,
        protected SerializableClosure $itemProvider,
        protected SerializableClosure $jobProvider,
    ) {
    }


    /**
     * @param WorkflowDefinitionParser $definitionParser
     * @param WorkflowRepository $workflowRepository
     * @return void
     * @throws Throwable
     */
    public function handle(
        WorkflowDefinitionParser $definitionParser,
        WorkflowRepository $workflowRepository
    ): void {
        try {
            /** @var callable():array<int|string,mixed> $provider */
            $provider = $this->itemProvider->getClosure();

            $middlewareClass = config('dag-workflows.resolvable_items_middleware');
            assert(is_string($middlewareClass));
            $middleware = resolve($middlewareClass);
            assert($middleware instanceof WorkflowResolvableItemsMiddleware);

            $items = $middleware->handle($provider());

            /** @var callable(mixed): (object|array<int, object>) $jobProvider */
            $jobProvider = $this->jobProvider->getClosure();

            /** @var Collection<int|string, Task> $definitions */
            $definitions = collect($items)->map(function($item, string|int $key) use ($jobProvider) {
                return new Task(
                    name: "{$this->name}:{$key}",
                    jobs: $jobProvider($item),
                    dependsOn: $this->dependsOn,
                );
            });

            $tasks = $definitionParser->parseTasksFromResolvable($definitions->values());

            $workflow = Workflow::query()->findOrFail($this->getWorkflowId());
            $workflowRepository->appendTasks($workflow, $tasks);
        } catch (Throwable $e) {
            Log::error('ResolvableTaskResolverJob failed', [
                'workflowId' => $this->getWorkflowId(),
                'taskName' => $this->name,
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
