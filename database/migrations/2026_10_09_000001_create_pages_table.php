<?php

use App\Support\DefaultPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The website's policy and information pages (privacy, terms, refunds,
 * account deletion …), edited in Admin → Website → Pages. Seeded with the
 * default text so the pages exist the moment this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 160);
            $table->string('summary', 300)->nullable();
            $table->longText('body')->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('show_in_footer')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        foreach (DefaultPages::all() as $slug => $page) {
            DB::table('pages')->insert([
                'slug' => $slug,
                'title' => $page['title'],
                'summary' => $page['summary'],
                'body' => DefaultPages::html($slug),
                'is_published' => true,
                'show_in_footer' => $page['footer'],
                'sort_order' => $page['sort'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
