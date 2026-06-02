# SSE Architecture & Implementation Guidelines
**Stack:** Laravel 10/11 | Vue 3 (Composition API) | FrankenPHP (Native SSE via Mercure Hub)

## 1. Core Philosophy: The Hybrid (Contextual) Approach
We do not use a single "catch-all" SSE topic per user, nor do we create a micro-topic for every single component. We use a **Hybrid Contextual Strategy**. 

Mercure Hub handles connection multiplexing, so the frontend should only subscribe to what is strictly necessary for the current UI context.

### Topic Levels
1. **Level 1: Global Notifications (Always Active)**
   - **Topic format:** `private-user-{id}-notifications`
   - **Lifecycle:** Connected on App initialization (`App.vue` or root router).
   - **Payload:** Lightweight JSON. Toasts, badge counters, global alerts.
   - **State:** Handled by Global Pinia Store.

2. **Level 2: Contextual/Route-Specific (Active per page/view)**
   - **Topic format:** `private-user-{id}-{context}` (e.g., `uploads`, `chat-room-45`, `dashboard-widgets`)
   - **Lifecycle:** Connected strictly in `onMounted` of the specific Vue component/route. Disconnected in `onUnmounted`.
   - **Payload:** Heavy/Complete JSON state snapshots (file lists, progress bars, chat messages).
   - **State:** Handled by Local Component State or Page-specific Pinia Store.

3. **Level 3: Public/Broadcast (Optional)**
   - **Topic format:** `public-site-news`, `public-btc-price`
   - **Lifecycle:** Same as Level 2.

---

## 2. Backend Implementation (Laravel + Mercure)

### Key Principle: Multi-Topic Publishing
Mercure allows sending a single event to **multiple topics** simultaneously. The backend should not check "where the user is currently looking". It just publishes to all relevant topics, and Mercure delivers only to active subscribers.

**Example: File Upload Finished**
```php
namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class FileUploadFinished implements ShouldBroadcast
{
    public function __construct(public int $userId, public array $fileData) {}

    public function broadcastOn()
    {
        // 1. Global topic (for toast notification)
        // 2. Contextual topic (for UI table update, IF user is on the uploads page)
        return [
            new PrivateChannel("user.{$this->userId}.notifications"), 
            new PrivateChannel("user.{$this->userId}.uploads"),       
        ];
    }

    public function broadcastWith()
    {
        return [
            'type' => 'file.uploaded',
            'data' => $this->fileData,
        ];
    }
}
```

---

## 3. Frontend Implementation (Vue 3)

### Connection Management
- **Always use HTTP/2** for the Mercure Hub URL to bypass the browser's HTTP/1.1 6-connection limit.
- **Never use raw `EventSource` directly in components.** Always use the designated composable.
- **Strict Unsubscription:** You MUST close the EventSource when the component unmounts to prevent memory leaks and zombie connections.
---

## 4. State Management Rules

- **Global Store (Pinia):** Listens ONLY to `Level 1` topics. Manages toast queues, global notification badges.
- **Local/Page State:** Listens to `Level 2` topics. When the user navigates away, `onUnmounted` fires, the connection drops, and the local state can be garbage collected (or cached if instant back-navigation is required).
- **Idempotency:** Vue handlers must be idempotent. If Mercure replays missed events via `Last-Event-ID`, the UI must not break if an item is added twice or updated out-of-order.

---

## 🤖 INSTRUCTIONS FOR AI AGENTS

When generating, modifying, or reviewing code for this project, you **MUST STRICTLY FOLLOW** these rules:

### Vue / Frontend Rules
1. **NEVER** create a single global `user-{id}` SSE listener to handle all app state. Always split into `notifications` (global) and `context` (local).
2. **ALWAYS** use the `useMercure` composable (or its approved successor) for SSE connections. Never instantiate `EventSource` directly inside a component's setup.
3. **ALWAYS** ensure SSE connections are closed. If writing a custom composable, `onUnmounted` MUST call `.close()`.
4. **DO NOT** put contextual SSE data (like file upload progress) into the Global Pinia store. Use local component state or a scoped page store.
5. **Payload Handling:** Always wrap `JSON.parse(event.data)` in a `try/catch` block inside the SSE handler to prevent the entire stream from breaking on a single malformed message.

### Laravel / Backend Rules
1. **ALWAYS** publish events to **both** the global notification topic AND the specific context topic when an action has both a UI impact and a notification impact. Let Mercure filter by active subscribers.
2. **Channel Naming:** Strictly follow the naming convention:
   - Global: `user.{userId}.notifications`
   - Context: `user.{userId}.{context_name}` (e.g., `user.12.uploads`)
   - Public: `public.{context_name}`
3. **Payload Structure:** Keep the JSON payload flat and predictable. Always include an event `type` or `action` key (e.g., `'type' => 'file.uploaded'`) so the frontend can switch on it easily.
4. **DO NOT** add logic in Laravel to check "if the user is currently on the uploads page" before broadcasting. Broadcast to the topic blindly; Mercure and the frontend handle the rest.

### Infrastructure / Config Rules
1. **HTTP/2:** Ensure that any configuration or environment variables related to the Mercure Hub URL enforce or expect HTTP/2 (e.g., `https://mercure.domain.com`).
2. **Security:** Never expose the raw Symfony/Mercure JWT secret in the frontend. The frontend should request a short-lived, scoped JWT from a Laravel endpoint (e.g., `/api/mercure/token`) which restricts the token to specific topics.
```
