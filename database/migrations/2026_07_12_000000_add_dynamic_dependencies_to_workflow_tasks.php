<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::table('workflow_tasks', function(Blueprint $table) {
            $table->json('dynamic_dependencies')->nullable()->after('name');
        });
    }


    public function down(): void {
        Schema::table('workflow_tasks', function(Blueprint $table) {
            $table->dropColumn('dynamic_dependencies');
        });
    }
};
