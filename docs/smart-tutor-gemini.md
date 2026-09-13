# Smart Tutor with Gemini

The student Smart Tutor uses Google's Gemini `generateContent` API through the server-side `GeminiSmartTutorGateway`.

Set these values in the deployment's `.env`:

```dotenv
GEMINI_API_KEY=your-google-ai-studio-key
GEMINI_MODEL=gemini-3.5-flash
```

Run `php artisan config:clear` after updating them (or rebuild the configuration cache with `php artisan config:cache` in production). Keep the key out of source control and browser/Vite variables.

Sign in as a student, open the Smart Tutor, start a conversation, and send a question. The gateway sends the tutor instructions, bounded conversation history, and educational stage/classroom context. It does not send student identifiers from the context array.

Missing configuration, network failures, rate limits, and blocked or incomplete responses use the existing safe tutor error handling. Failed questions remain saved; no empty answer is added. Requests time out after 60 seconds and are not automatically retried.

Run the integration and conversation tests:

```sh
php artisan test --filter='GeminiSmartTutorGatewayTest|StudentSmartTutorTest|StudentSmartTutorReliabilityTest'
```

Tests use fake HTTP responses and an empty test key configuration, so they do not call Google or consume API quota.

API reference: https://ai.google.dev/api/generate-content
