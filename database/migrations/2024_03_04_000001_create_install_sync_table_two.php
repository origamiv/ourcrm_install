<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('one')->create('install_sync', function (Blueprint $table) {
            $table->id();
            $table->string('schema_name')->comment('схема');
            $table->string('table_name')->comment('название таблицы');
            $table->bigInteger('last_auto_increment_id')->default(0)->comment('последний id автоинкремента');
            $table->bigInteger('count_one')->default(0)->comment('число записей в бд one');
            $table->bigInteger('count_two')->default(0)->comment('число записей в бд two');
            $table->boolean('is_trigger_active')->default(false)->comment('активен ли триггер');
            $table->decimal('completion_percentage', 5, 2)->default(0)->comment('% выполнения');
            $table->string('status')->default('pending')->comment('статус');
            $table->timestamps();

            $table->unique(['schema_name', 'table_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('one')->dropIfExists('install_sync');
    }
};
