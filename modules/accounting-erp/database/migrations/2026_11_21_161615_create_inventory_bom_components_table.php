<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_bom_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
            $table->unique(['item_id', 'component_item_id'], 'inventory_bom_item_component_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_bom_components');
    }
};
