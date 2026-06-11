<template>
    <main class="mx-auto max-w-3xl p-6">
        <h1 class="mb-4 text-2xl font-semibold">SSE debug (Mercure)</h1>

        <div class="mb-4 rounded border border-gray-300 p-3 text-sm">
            <p><strong>Status:</strong> {{ status }}</p>
            <p><strong>Topic:</strong> {{ topic || 'loading...' }}</p>
        </div>

        <form class="mb-4 flex gap-2" @submit.prevent="sendMessage">
            <input
                v-model="draft"
                class="w-full rounded border border-gray-300 px-3 py-2"
                placeholder="Введите сообщение"
                type="text"
            >
            <button
                class="rounded bg-black px-4 py-2 text-white disabled:opacity-50"
                :disabled="sending || !draft.trim()"
                type="submit"
            >
                {{ sending ? 'Sending...' : 'Send' }}
            </button>
        </form>

        <div class="rounded border border-gray-300 p-3">
            <h2 class="mb-2 font-medium">Events</h2>
            <ul class="space-y-2 text-sm">
               <li v-for="(event, index) in events" :key="index" class="rounded bg-gray-100 p-2">

                
                 <a class="block text-blue-500 hover:underline pb-2" v-if="event.url"  :href="event.url" target="_blank">
            {{ event.url }}
                 </a>

        <div v-if="event.text" class="whitespace-pre-wrap">
            {{ event.text }}
        </div>
        <div v-else-if="typeof event === 'string'" class="whitespace-pre-wrap">
            {{ event }}
        </div>
        <pre v-else class="text-xs">{{ JSON.stringify(event, null, 2) }}</pre>
        
        <!-- Ссылки если есть -->
        <div v-if="event.links && event.links.length" class="mt-2 text-sm">
            <strong>Ссылки:</strong>
            <a v-for="(link, i) in event.links" :key="i" 
               :href="link" target="_blank" 
               class="block text-blue-500 hover:underline">
                {{ link }}
            </a>
        </div>
    </li>
            </ul>
        </div>
    </main>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

const status = ref('connecting')
const topic = ref('')
const draft = ref('')
const sending = ref(false)
const events = ref([])

let eventSource = null
let publishUrl = ''

const addEvent = (message) => {
    let msg = null
    try {
        msg = JSON.parse(message)
    } catch {
        msg = message
    }
    events.value.unshift(msg)
    events.value = events.value.slice(0, 30)
}

const connect = async () => {
    const response = await fetch('/api/debug/sse/config')

    if (!response.ok) {
        status.value = 'failed to load config'
        return
    }

    const config = await response.json()
    topic.value = config.topic
    publishUrl = config.publish_url

    const streamUrl = `${config.hub_url}?topic=${encodeURIComponent(config.topic)}`
    eventSource = new EventSource(streamUrl)

    eventSource.onopen = () => {
        status.value = 'connected'
        addEvent(`[system] connected to ${config.topic}`)
    }

    eventSource.onmessage = (event) => {
        addEvent(event.data)
    }

    eventSource.onerror = () => {
        status.value = 'reconnecting'
    }
}

const sendMessage = async () => {
    const message = draft.value.trim()

    if (!message || !publishUrl) {
        return
    }

    sending.value = true

    try {
        const response = await fetch(publishUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ message }),
        })

        if (!response.ok) {
            addEvent('[system] publish failed')
            return
        }

        draft.value = ''
    } finally {
        sending.value = false
    }
}

onMounted(() => {
    connect()
})

onBeforeUnmount(() => {
    eventSource?.close()
})
</script>
<style scoped>
.whitespace-pre-wrap {
    white-space: pre-wrap;
}
</style>