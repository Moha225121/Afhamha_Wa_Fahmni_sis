<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assignments')) {
            Schema::create('assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
                $table->string('title');
                $table->text('instructions')->nullable();
                $table->dateTime('due_at')->nullable()->index();
                $table->string('status', 20)->default('draft')->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assignment_attachments')) {
            Schema::create('assignment_attachments', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
                    $table->string('path');
                    $table->string('original_name');
                    $table->string('mime_type', 120)->nullable();
                    $table->unsignedBigInteger('size')->nullable();
                    $table->timestamps();
            });
        } else {
            Schema::table('assignment_attachments', function (Blueprint $table): void {
                if (! Schema::hasColumn('assignment_attachments', 'path')) {
                    $table->string('path')->nullable();
                }
                if (! Schema::hasColumn('assignment_attachments', 'size')) {
                    $table->unsignedBigInteger('size')->nullable();
                }
            });
        }

        if (! Schema::hasTable('assignment_submission_attachments')) {
            Schema::create('assignment_submission_attachments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assignment_submission_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->string('original_name');
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 30)->default('aes128gcm');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'endpoint'], 'push_subscriptions_user_endpoint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('assignment_submission_attachments');
            if (Schema::hasTable('assignment_attachments')) {
                Schema::table('assignment_attachments', function (Blueprint $table): void {
                    if (Schema::hasColumn('assignment_attachments', 'path')) {
                        $table->dropColumn('path');
                    }
                    if (Schema::hasColumn('assignment_attachments', 'size')) {
                        $table->dropColumn('size');
                    }
                });
            }
    }
};
