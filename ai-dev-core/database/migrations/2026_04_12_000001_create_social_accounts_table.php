<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->enum('platform', [
                'facebook',
                'instagram',
                'twitter',
                'linkedin',
                'tiktok',
                'youtube',
                'pinterest',
                'telegram',
            ]);
            $table->string('account_name', 100)->comment('Nome legível da conta (ex: Fan Page ItaloAndrade)');
            $table->text('credentials')->comment('JSON criptografado com tokens e chaves API da plataforma');
            $table->boolean('is_active')->default(true);
            $table->timestamp('token_expires_at')->nullable()->comment('Expiração do token de acesso (para renovação proativa)');
            $table->timestamp('last_posted_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'platform']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
