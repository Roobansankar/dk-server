<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the catalogue references on `appointments`.
 *
 * `service_id` / `service_category_id` are filtered on by the admin appointment
 * history and payments report; without an index those queries scan the table.
 * No foreign key is added by design — appointments keep a denormalised snapshot
 * of what was booked (`service_name`, `category_name`, price, advance) so history
 * stays intact even if a service is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! $this->hasIndex('appointments', 'appointments_service_id_index')) {
                $table->index('service_id');
            }
            if (! $this->hasIndex('appointments', 'appointments_service_category_id_index')) {
                $table->index('service_category_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if ($this->hasIndex('appointments', 'appointments_service_id_index')) {
                $table->dropIndex(['service_id']);
            }
            if ($this->hasIndex('appointments', 'appointments_service_category_id_index')) {
                $table->dropIndex(['service_category_id']);
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $index);
    }
};
