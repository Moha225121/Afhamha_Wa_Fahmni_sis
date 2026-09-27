# HEXA-AF-STU-01 DELIVERY REPORT

## Delivery Summary

### Implemented

- A student dashboard driven by the authenticated student's class, schedule, and audience-scoped announcements.
- Class-scoped active subjects, ordered units, published/non-future lessons, and authorized lesson attachments.
- A searchable, filterable, paginated library with class/subject visibility rules and authorized public/private downloads.
- A responsive RTL Smart Tutor interface with conversation/message persistence, pagination, strict validation, per-student rate limits, idempotent UUID handling, bounded context, safe gateway errors, atomic terminal states, and stale-pending recovery.
- Offline fake-gateway, security, reliability, storage, migration, and education coverage.

### Files Changed

Student education delivery files:

- `app/Http/Controllers/StudentPortal/PortalController.php`
- `app/Http/Controllers/StudentPortal/EducationController.php`
- `app/Http/Controllers/StudentPortal/TutorController.php`
- `app/Http/Requests/TutorMessageRequest.php`
- `app/Models/Student.php`, `Subject.php`, `Unit.php`, `Lesson.php`, `LessonAttachment.php`, `LibraryResource.php`, `TutorConversation.php`, and `TutorMessage.php`
- `app/Contracts/SmartTutorGateway.php`
- `app/Data/SmartTutorPrompt.php`, `SmartTutorReply.php`, and `SmartTutorTurn.php`
- `app/Exceptions/SmartTutorGatewayException.php` and `SmartTutorUnavailableException.php`
- `app/Services/SmartTutorConversationService.php` and `UnavailableSmartTutorGateway.php`
- `app/Providers/AppServiceProvider.php`, `config/smart_tutor.php`, and `routes/student.php`
- `resources/views/student/dashboard.blade.php`, `layout.blade.php`, `profile.blade.php`, and the `subjects/`, `lessons/`, `library/`, and `tutor/` view directories
- `public/css/parent.css` and `public/js/student-tutor.js`
- migrations `2026_08_23_000003` through `2026_08_23_000006`
- `tests/Concerns/CreatesStudentEducationFixtures.php` and the Student Education, Library, Smart Tutor, and Tutor Delivery Metadata feature tests
- `docs/HEXA-AF-STU-01.md`

The working tree also contains preceding library integration changes in `app/Http/Controllers/Admin/OperationsController.php` and `app/Http/Requests/LibraryResourceRequest.php`. This final audit did not edit those Admin files.

### Database Changes

- `000003` creates units, lessons, lesson attachments, and library resources with their subject/class/creator relationships and indexes.
- `000004` records the resource disk and migrates legacy private resources from public to local storage with copy verification.
- `000005` creates student-owned tutor conversations and their messages.
- `000006` adds request UUIDs, delivery states, safe failure reasons, one-answer links, unique constraints, context indexes, legacy backfill, and ordered rollback.

Migration 000006 requires management approval before merge because it changes the shared database schema.

### Routes Added

All routes are inside the authenticated `student` middleware group:

- `GET /student/subjects`
- `GET /student/subjects/{subject}`
- `GET /student/subjects/{subject}/lessons`
- `GET /student/subjects/{subject}/lessons/{lesson}`
- `GET /student/subjects/{subject}/lessons/{lesson}/attachments/{attachment}`
- `GET /student/library`
- `GET /student/library/{resource}/download`
- `GET /student/tutor`
- `POST /student/tutor/conversations` — 10 new conversations per minute per authenticated user
- `GET /student/tutor/conversations/{conversation}`
- `POST /student/tutor/conversations/{conversation}/messages` — 20 messages per minute per authenticated user

Numeric route constraints apply to record identifiers.

### Authorization

The authenticated user is resolved to a student record. Subjects begin from the student's class relationship; lessons begin from the authorized subject; attachments begin from the authorized lesson; library resources use the student visibility scope; and tutor conversations begin from the student's conversation relationship. Cross-student/class IDs return a safe 404, and conversation ownership is established before any gateway call.

### Storage Security

Public resources use the `public` disk. Non-public resources use the `local` disk and never receive a direct `/storage/...` link. Every resource and attachment download repeats record authorization, accepts only the `local` or `public` disk, verifies file existence, sanitizes the download name, and adds `X-Content-Type-Options: nosniff`.

### AI Integration

`SmartTutorGateway` is provider-neutral. `UnavailableSmartTutorGateway` is the safe runtime fallback, and tests bind deterministic fake gateways without network access. No provider SDK, live credential, browser-side key, or provider base URL is included.

AI provider integration is intentionally reserved for the project supervisor.

### Conversations & Messages

Conversations belong to the authenticated student. A question is stored as `pending` before the external call. Success atomically creates one validated assistant reply and changes the question to `answered`; failure retains the question and changes only a still-pending row to `failed`. Conversation-scoped UUID uniqueness prevents duplicate requests, one-answer uniqueness prevents duplicate replies, and stale pending questions become recoverable without an automatic second provider call.

### Tests

The final SQLite in-memory suite passed with 62 tests and 480 assertions. It includes education/publication scope, resource and attachment IDOR, private storage, message validation, Arabic content, success/failure persistence, timeout, rate-limit, upstream 500, empty/malformed/overlong reply, UUID replay/conflict, uniqueness constraints, stale recovery, race interleavings, bounded/isolation-safe context, route limit isolation, pagination, and migration backfill/rollback.

### Acceptance Criteria

| Criterion | Result | Evidence |
| --- | --- | --- |
| Dashboard → Subjects → Subject → Lesson → Library → Smart Tutor navigation | PASS | Authenticated named routes, Blade links, and feature tests load every implemented area. |
| Student sees only subjects for the student's class | PASS | Relationship-scoped queries and cross-class tests. |
| Student sees only published, non-future lessons | PASS | Published scope plus draft/future feature tests. |
| Content and files originate from database/storage | PASS | Eloquent-backed views and fake-disk download tests. |
| No AI credential or service secret appears in frontend assets | PASS | Escaped Blade, server-only contract, and secret-rendering regression test. |
| Conversations/messages persist and belong to the authenticated student | PASS | Ownership, IDOR, persistence, replay, and migration tests. |
| Interfaces are responsive and RTL | PASS | RTL layout, responsive CSS breakpoints, focus states, labels, and accessible pagination. |
| Live provider response | BLOCKED | Supervisor-owned adapter and approved credential are intentionally not part of this engineer scope. |
| Dashboard assignments summary | BLOCKED | Awaiting the HEXA-AF-STU-02 assignments data model. |
| PostgreSQL smoke test | BLOCKED | No disposable PostgreSQL environment is available. |
| Merge of migration `000006` | BLOCKED | Management approval is required before merge. |

Verified application path:

```text
Dashboard → Subjects → Subject → Lesson → Library → Smart Tutor
```

## 1. Scope

This delivery covers the authenticated student dashboard, class-scoped subjects, units and published lessons, lesson attachments, the digital library, and the Smart Tutor user interface and message persistence. Its security boundaries include student ownership, class and publication scope, private-file authorization, server-side validation, rate limiting, conversation isolation, and idempotent message delivery.

The following work is deliberately external to this delivery:

- AI provider integration is intentionally reserved for the project supervisor.
- Dashboard task summary awaits the HEXA-AF-STU-02 assignments data model.
- A PostgreSQL smoke test awaits an isolated, disposable PostgreSQL environment.

No assignment model is inferred from `teacher_assignments`; that table represents teaching allocation rather than student assignments.

## 2. Architecture

```text
Browser
→ Authenticated Student Route
→ Form Request
→ Student Controller
→ SmartTutorConversationService
→ SmartTutorGateway
→ TutorMessage persistence
→ Redirect
→ Escaped Blade response
```

The browser never receives provider credentials or a provider base URL. The controller resolves a conversation through the current student's relationship before the conversation service can call the gateway.

## 3. File Responsibilities

| File | Responsibility |
| --- | --- |
| `app/Http/Requests/TutorMessageRequest.php` | Normalizes the submitted text and UUID, enforces the 2–4,000 character boundary, rejects malformed UTF-8, disallowed controls, invisible-only content, invalid UUIDs, and unexpected fields. |
| `app/Http/Controllers/StudentPortal/TutorController.php` | Resolves the authenticated student and owned conversation, expires stale pending questions, paginates history, invokes the service, and maps domain failures to safe Arabic messages. |
| `app/Services/SmartTutorConversationService.php` | Persists the question before the external call, enforces idempotency and atomic state transitions, builds bounded context, validates replies, and records safe failure metadata. |
| `app/Contracts/SmartTutorGateway.php` | Provider-neutral contract accepting a `SmartTutorPrompt` and returning a `SmartTutorReply`. |
| `app/Services/UnavailableSmartTutorGateway.php` | Safe fallback used until the supervisor installs an approved adapter; it performs no network call. |
| `app/Models/TutorConversation.php` | Owns the student-to-conversation and conversation-to-message relationships. |
| `app/Models/TutorMessage.php` | Represents user/assistant content, delivery state, request UUID, and the one-to-one answer link. |
| `config/smart_tutor.php` | Central limits for input, replies, context, stale pending age, pagination, and per-student rate limits. |
| `public/js/student-tutor.js` | Prevents repeated UI submission, displays a loading state, restores controls after browser navigation, and prepares a failed question for a new request without injecting HTML. |
| `database/migrations/2026_08_23_000006_add_delivery_metadata_to_tutor_messages.php` | Adds delivery metadata, conversation-scoped request uniqueness, one-answer uniqueness, the self-reference, indexes, legacy backfill, and ordered rollback. |

## 4. Message Lifecycle

```text
pending → answered
pending → failed
```

The service first inserts the student's question as `pending`. The gateway call then runs outside a long database transaction. A validated, non-empty reply is saved in the same short transaction that changes the question to `answered`; the assistant row points to that question. A gateway, validation, or persistence failure retains the question and uses a compare-and-set update that can only change `pending` to `failed`. This prevents a late failure or stale cleanup from overwriting an already completed answer.

Questions still `pending` after the configured timeout are marked `failed` with the internal `stale_pending` reason when the owned conversation is opened. No gateway retry occurs during recovery. The interface then offers to copy the question into the composer for an explicit new request.

## 5. Idempotency

The server renders a UUID in the message form. Validation normalizes it to lowercase, and the database unique index scopes it to `(tutor_conversation_id, client_request_id)`, so different conversations may use the same UUID without collision.

- Same UUID and same question after success: return the one valid linked answer without calling the gateway again.
- Same UUID and different question: reject as a request conflict.
- Same UUID while recent `pending`: report that processing is in progress, preserve the same UUID and text in the form, and do not call the gateway again.
- Same UUID after a terminal failure: return the stored safe failure without a second provider call.
- Concurrent inserts: Laravel's `createOrFirst` handles the unique-key race, while the database constraint is the final defence.
- Concurrent completion and stale/failure handling: row locking plus compare-and-set transitions produce one terminal state and at most one linked assistant reply.
- Stale `pending`: mark failed without contacting the gateway; a user-initiated retry uses a new UUID because the prior provider outcome is unknown.

After a successful or terminal request, a newly rendered page receives a new UUID. Browser resubmission of the same in-flight form retains its existing UUID.

## 6. Authorization

- Tutor conversations are queried through `Student::tutorConversations()` for the current authenticated student. An altered conversation ID therefore returns a safe 404 before any gateway call.
- Subjects originate from the active subjects attached to the student's class. Units are resolved beneath that subject, and only published, non-future lessons are accessible.
- Lesson attachments are resolved beneath the already authorized lesson rather than from an unconstrained attachment lookup.
- Library resources must match the student's class/subject rules. Public resources use the public disk; non-public resources use the local disk and are returned through an authorized download action rather than `/storage/...`.
- IDs used by these routes have numeric constraints. Blade escapes displayed titles, questions, replies, and educational content by default.

These relationship-scoped lookups prevent cross-student and cross-class IDOR attempts from revealing records.

## 7. AI Provider Integration for the Supervisor

AI provider integration is intentionally reserved for the project supervisor.

1. Create an adapter that implements `SmartTutorGateway` without changing the controller or Blade views.
2. Store the approved credential only in the server environment.
3. Read timeouts and provider settings through Laravel configuration.
4. Bind the adapter to `SmartTutorGateway` in the service container while retaining `UnavailableSmartTutorGateway` as the safe fallback when integration is unavailable.
5. Translate provider timeout, connection, rate-limit, client, server, empty, malformed, invalid-encoding, and excessive-length failures into the existing internal exceptions.
6. Never expose credentials, authorization headers, or provider URLs to Blade or JavaScript.
7. Run an approved live smoke test in a non-production environment.
8. Confirm that one student question and one linked assistant answer are persisted on success.
9. Review logs to confirm that question text, raw responses, credentials, headers, and stack traces are absent.

The adapter must respect the supplied ordered turns and sanitized context; it must not perform its own persistence or bypass the service lifecycle.

## 8. Testing

The feature tests bind in-memory fake gateways to the provider-neutral contract and make no internet request. Contract tests verify prompts, persistence, idempotency, ownership, validation, context limits, safe errors, state races, stale recovery, rate-limit isolation, pagination, and database uniqueness. These tests are not a substitute for the supervisor's eventual live-provider smoke test.

Final offline PHPUnit result on 2026-08-24:

```text
Tests: 62
Assertions: 480
Failures: 0
Errors: 0
Skipped: 0
```

The suite ran against SQLite `:memory:` with the array cache and session drivers configured in `phpunit.xml`. PostgreSQL was not available and was not simulated.

Safe verification commands:

```bash
php artisan test
php artisan test --filter=StudentSmartTutorTest
php artisan test --filter=StudentSmartTutorReliabilityTest
php artisan test --filter=TutorDeliveryMetadataMigrationTest
php artisan test --filter=StudentEducationTest
php artisan test --filter=StudentLibraryTest
node --check public/js/student-tutor.js
```

```text
POSTGRESQL SMOKE TEST: NOT EXECUTED — ENVIRONMENT UNAVAILABLE
```

## 9. Deployment Notes

1. Back up the target database and relevant storage before deployment.
2. Obtain management approval for shared-schema changes.
3. Apply and verify migrations on PostgreSQL staging before production. Never use `migrate:fresh` on a shared environment.
4. Confirm the intended cache, session, and queue configuration and rebuild the Laravel configuration cache using the deployment process.
5. Confirm local/public storage configuration and the public storage link required by public resources.
6. Have the supervisor install the approved gateway adapter and run the live AI smoke test.
7. Verify authentication, conversation ownership, one successful exchange, safe provider failure, and log redaction.
8. Keep a rollback plan that restores the database backup. Migration rollback alone cannot reverse an external provider request and should only run after checking shared-schema dependencies.

Migration 000006 requires management approval before merge because it changes the shared database schema.

## 10. Known External Dependencies

```text
AI provider: Supervisor-owned integration.
Dashboard assignments summary: Awaiting HEXA-AF-STU-02 data model.
PostgreSQL smoke test: Awaiting disposable PostgreSQL environment.
```
