<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * @return void
     */
    public function up(): void {
        Schema::table('workflows', function(Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('failed_at');
            $table->string('pause_reason', 1024)->nullable()->after('paused_at');
        });

        Schema::table('workflow_tasks', function(Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('failed_at');
            $table->string('pause_reason', 1024)->nullable()->after('paused_at');
        });

        Schema::table('workflow_task_steps', function(Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('failed_at');
            $table->string('pause_reason', 1024)->nullable()->after('paused_at');
        });
    }


    /**
     * @return void
     */
    public function down(): void {
        Schema::table('workflows', function(Blueprint $table) {
            $table->dropColumn(['paused_at', 'pause_reason']);
        });

        Schema::table('workflow_tasks', function(Blueprint $table) {
            $table->dropColumn(['paused_at', 'pause_reason']);
        });

        Schema::table('workflow_task_steps', function(Blueprint $table) {
            $table->dropColumn(['paused_at', 'pause_reason']);
        });
    }
};
