<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen project_delivery_addresses to cover contact-led destinations.
 *
 * The table was modelled on a site address —
 *
 *     Harrington Residence — Full Renovation
 *     88 Ridgeview Court
 *     Wheaton, IL 60187
 *     United States
 *
 * — but real destinations also take the shape of a named contact at a company
 * location, with no street line at all:
 *
 *     Tyler Blake
 *     PWC Companies – PWC Headquarters
 *     Cornwall-on-Hudson, NY
 *
 * Two changes let one table print both:
 *
 *  - street_1 becomes NULLABLE. It was NOT NULL, which made the second shape
 *    impossible to store rather than merely awkward. `city` stays required, and
 *    the FormRequests additionally require at least one of label / attention,
 *    so an address can still never be a row of blanks.
 *
 *  - country loses its 'United States' DEFAULT. A stored default prints on
 *    every document whether or not anyone chose it, which put a line on the
 *    contact-led block that does not belong there. It is now written only when
 *    someone actually enters it.
 *
 * Existing rows are untouched: any address already carrying a street or an
 * explicit country keeps both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_delivery_addresses', function (Blueprint $table) {
            $table->string('street_1', 200)->nullable()->change();

            // label too: a contact-led destination may be addressed to a person
            // alone. The FormRequests require label OR attention, so the pair
            // can never both be blank — but neither is individually mandatory,
            // and a NOT NULL here would reject "Tyler Blake, Cornwall-on-Hudson"
            // at the database before the application ever sees it.
            $table->string('label', 200)->nullable()->change();

            // change() redefines the column from what is specified here, so
            // omitting ->default() is what actually drops it.
            $table->string('country', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_delivery_addresses', function (Blueprint $table) {
            $table->string('street_1', 200)->nullable(false)->change();
            $table->string('label', 200)->nullable(false)->change();
            $table->string('country', 120)->nullable()->default('United States')->change();
        });
    }
};
