<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * @return void
     */
    public function up(): void {
        Schema::create('workflows', function(Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_tasks', function(Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->index()->constrained();
            $table->string('name');
            $table->string('status')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_task_steps', function(Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->index()->constrained('workflow_tasks');
            $table->foreignId('workflow_id')->index();
            $table->unsignedInteger('order')->index();
            $table->string('class', 1024);
            $table->string('status')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->longText('payload');
            $table->timestamps();
        });

        Schema::create('workflow_task_dependencies', function(Blueprint $table) {
            $table->foreignId('task_id')->index()->constrained('workflow_tasks');
            $table->foreignId('dependant_task_id')->index()->constrained('workflow_tasks');
        });
    }


    /**
     * @return void
     */
    public function down(): void {
        Schema::dropIfExists('workflow_task_dependencies');
        Schema::dropIfExists('workflow_task_steps');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflows');
    }
};
