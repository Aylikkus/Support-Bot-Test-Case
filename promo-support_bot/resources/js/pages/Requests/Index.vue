<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    close,
    show,
    stats as statsRoute,
    storeMessage,
} from '@/actions/App/Http/Controllers/SupportRequestController';
import { getEcho } from '@/lib/echo';
import type {
    ChatMessage,
    QueueItem,
    ReverbConfig,
    SelectedRequest,
    SupportStats,
} from '@/types';

const props = defineProps<{
    requests: QueueItem[];
    selected: SelectedRequest | null;
}>();

const page = usePage();
const reverb = computed(() => page.props.reverb as ReverbConfig);

const form = useForm({ text: '' });
const messagesEl = ref<HTMLElement | null>(null);
const connection = ref<'connecting' | 'live' | 'offline'>('connecting');

const statsOpen = ref(false);
const statsLoading = ref(false);
const statsError = ref<string | null>(null);
const stats = ref<SupportStats | null>(null);

const operatorName = computed(() => page.props.auth.operator?.name);

function logout(): void {
    router.post('/logout');
}

const isOpen = computed(() => props.selected?.status === 'open');

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function preview(message: ChatMessage): string {
    if (message.text) {
        return message.text;
    }

    return message.message_type === 'image'
        ? '🖼 Изображение'
        : message.message_type === 'file'
          ? `📎 ${message.file?.name ?? 'Файл'}`
          : '';
}

function send(): void {
    if (!props.selected || form.text.trim() === '' || form.processing) {
        return;
    }

    form.post(storeMessage.url(props.selected.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('text'),
    });
}

function closeRequest(): void {
    if (!props.selected || !confirm('Закрыть обращение?')) {
        return;
    }

    router.post(close.url(props.selected.id));
}

async function openStats(): Promise<void> {
    statsOpen.value = true;
    statsLoading.value = true;
    statsError.value = null;

    try {
        const response = await fetch(statsRoute.url(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('bad-status');
        }

        const payload = (await response.json()) as SupportStats;
        stats.value = payload;
    } catch {
        statsError.value = 'Не удалось загрузить статистику.';
    } finally {
        statsLoading.value = false;
    }
}

function closeStats(): void {
    statsOpen.value = false;
}

function scrollToBottom(): void {
    void nextTick(() => {
        if (messagesEl.value) {
            messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
        }
    });
}

// Live updates: refetch props whenever the server says something changed.
// Reloads are coalesced so a burst of events results in at most one extra request.
let reloading = false;
let reloadQueued = false;

function refresh(): void {
    if (reloading) {
        reloadQueued = true;

        return;
    }

    reloading = true;
    router.reload({
        only: ['requests', 'selected'],
        onFinish: () => {
            reloading = false;

            if (reloadQueued) {
                reloadQueued = false;
                refresh();
            }
        },
    });
}

watch(() => props.selected?.messages.length, scrollToBottom);
watch(() => props.selected?.id, scrollToBottom);

onMounted(() => {
    scrollToBottom();

    const echo = getEcho(reverb.value);

    if (!echo) {
        connection.value = 'offline';

        return;
    }

    echo.channel('support-requests').listen('.updated', refresh);

    const pusher = echo.connector.pusher;
    pusher.connection.bind('state_change', ({ current }: { current: string }) => {
        const wasLive = connection.value === 'live';
        connection.value =
            current === 'connected'
                ? 'live'
                : current === 'connecting'
                  ? 'connecting'
                  : 'offline';

        // Events may have been missed while disconnected.
        if (connection.value === 'live' && !wasLive) {
            refresh();
        }
    });
});

onBeforeUnmount(() => {
    getEcho(reverb.value)?.leaveChannel('support-requests');
});
</script>

<template>
    <Head title="Обращения" />

    <div class="relative flex h-screen bg-gray-50 text-gray-900">
        <aside class="flex w-80 shrink-0 flex-col border-r border-gray-200 bg-white">
            <header class="border-b border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <h1 class="font-semibold">Очередь обращений</h1>
                    <span
                        class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium"
                        data-testid="queue-count"
                    >
                        {{ requests.length }}
                    </span>
                </div>
                <p class="mt-1 flex items-center justify-between text-xs text-gray-500">
                    <span data-testid="operator-name">{{ operatorName }}</span>
                    <button type="button" class="hover:text-gray-900 hover:underline" @click="logout">
                        Выйти
                    </button>
                </p>
                <p class="mt-1 flex items-center gap-1.5 text-xs text-gray-500">
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="{
                            'bg-green-500': connection === 'live',
                            'bg-yellow-400': connection === 'connecting',
                            'bg-red-500': connection === 'offline',
                        }"
                    />
                    {{
                        connection === 'live'
                            ? 'Обновляется в реальном времени'
                            : connection === 'connecting'
                              ? 'Подключение…'
                              : 'Нет соединения'
                    }}
                </p>
            </header>

            <ul class="flex-1 overflow-y-auto">
                <li v-if="requests.length === 0" class="p-4 text-sm text-gray-500">
                    Открытых обращений нет.
                </li>
                <li v-for="item in requests" :key="item.id">
                    <Link
                        :href="show(item.id)"
                        preserve-scroll
                        class="block border-b border-gray-100 px-4 py-3 hover:bg-gray-50"
                        :class="{ 'bg-blue-50': selected?.id === item.id }"
                    >
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">#{{ item.id }} · {{ item.user_id }}</span>
                            <span class="text-xs text-gray-500">
                                {{ formatTime(item.last_message?.created_at ?? item.created_at) }}
                            </span>
                        </div>
                        <p class="mt-1 truncate text-sm text-gray-600">
                            {{ item.last_message ? preview(item.last_message) : '' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-400">
                            Сообщений: {{ item.messages_count }}
                        </p>
                    </Link>
                </li>
            </ul>
        </aside>

        <main class="flex min-w-0 flex-1 flex-col">
            <div
                v-if="!selected"
                class="flex flex-1 items-center justify-center text-gray-500"
            >
                Выберите обращение из очереди
            </div>

            <template v-else>
                <header class="flex items-center justify-between border-b border-gray-200 bg-white p-4">
                    <div>
                        <h2 class="font-semibold">
                            Обращение #{{ selected.id }}
                            <span
                                class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="isOpen ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700'"
                            >
                                {{ isOpen ? 'открыто' : 'закрыто' }}
                            </span>
                        </h2>
                        <p class="text-xs text-gray-500">
                            Пользователь {{ selected.user_id }} · создано
                            {{ formatTime(selected.created_at) }}
                        </p>
                    </div>
                    <button
                        v-if="isOpen"
                        type="button"
                        class="rounded-md border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50"
                        @click="closeRequest"
                    >
                        Закрыть обращение
                    </button>
                </header>

                <div ref="messagesEl" class="flex-1 space-y-3 overflow-y-auto p-4">
                    <div
                        v-for="message in selected.messages"
                        :key="message.id"
                        class="flex"
                        :class="message.sender_type === 'user' ? 'justify-start' : 'justify-end'"
                    >
                        <div
                            class="max-w-[70%] rounded-lg px-3 py-2 text-sm shadow-sm"
                            :class="message.sender_type === 'user' ? 'bg-white' : 'bg-blue-600 text-white'"
                        >
                            <a
                                v-if="message.image_url"
                                :href="message.image_url"
                                target="_blank"
                                rel="noopener"
                            >
                                <img
                                    :src="message.image_url"
                                    alt="Изображение"
                                    loading="lazy"
                                    class="mb-1 max-h-72 rounded"
                                    @load="scrollToBottom"
                                />
                            </a>
                            <p
                                v-else-if="message.message_type === 'image'"
                                class="italic opacity-70"
                            >
                                Изображение недоступно
                            </p>
                            <a
                                v-if="message.file"
                                :href="message.file.url"
                                download
                                class="mb-1 block break-all underline"
                            >
                                Загрузить файл {{ message.file.name }}
                            </a>
                            <p
                                v-if="message.text"
                                class="break-words whitespace-pre-wrap"
                            >
                                {{ message.text }}
                            </p>
                            <p
                                class="mt-1 text-right text-[11px]"
                                :class="message.sender_type === 'user' ? 'text-gray-400' : 'text-blue-100'"
                            >
                                {{ formatTime(message.created_at) }}
                            </p>
                        </div>
                    </div>
                </div>

                <form
                    v-if="isOpen"
                    class="border-t border-gray-200 bg-white p-4"
                    @submit.prevent="send"
                >
                    <p v-if="form.errors.text" class="mb-2 text-sm text-red-600">
                        {{ form.errors.text }}
                    </p>
                    <div class="flex gap-2">
                        <textarea
                            v-model="form.text"
                            rows="2"
                            maxlength="4096"
                            placeholder="Ответ пользователю (Ctrl+Enter — отправить)"
                            class="flex-1 resize-none rounded-md border border-gray-300 p-2 text-sm focus:border-blue-500 focus:outline-none"
                            @keydown.ctrl.enter.prevent="send"
                        />
                        <button
                            type="submit"
                            :disabled="form.processing || form.text.trim() === ''"
                            class="self-end rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            Отправить
                        </button>
                    </div>
                </form>
                <p v-else class="border-t border-gray-200 bg-white p-4 text-sm text-gray-500">
                    Обращение закрыто, отправка сообщений недоступна.
                </p>
            </template>
        </main>

        <button
            type="button"
            class="fixed bottom-4 left-4 z-20 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 shadow hover:bg-gray-50"
            @click="openStats"
        >
            Статистика
        </button>

        <div
            v-if="statsOpen"
            class="fixed inset-0 z-30 flex items-center justify-center bg-black/40 p-4"
            @click.self="closeStats"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-lg">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold">Статистика</h3>
                    <button
                        type="button"
                        class="rounded px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                        @click="closeStats"
                    >
                        ✕
                    </button>
                </div>

                <p v-if="statsLoading" class="text-sm text-gray-500">Загрузка…</p>
                <p v-else-if="statsError" class="text-sm text-red-600">{{ statsError }}</p>

                <dl v-else-if="stats" class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-4 border-b border-gray-100 pb-2">
                        <dt class="text-gray-600">Закрыто ботом</dt>
                        <dd class="font-semibold text-gray-900">{{ stats.bot_closed_count }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-b border-gray-100 pb-2">
                        <dt class="text-gray-600">Передано операторам</dt>
                        <dd class="font-semibold text-gray-900">{{ stats.forwarded_count }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-gray-600">Среднее время ответа оператора</dt>
                        <dd class="text-right font-semibold text-gray-900">{{ stats.avg_operator_response_time }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</template>
