import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { ReverbConfig } from '@/types';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

let instance: Echo<'reverb'> | null = null;

/** Lazily creates one Echo (Reverb) connection shared by the whole page. */
export function getEcho(config: ReverbConfig): Echo<'reverb'> | null {
    if (!config.key) {
        return null;
    }

    if (instance === null) {
        window.Pusher = Pusher;

        instance = new Echo({
            broadcaster: 'reverb',
            key: config.key,
            wsHost: config.host,
            wsPort: config.port,
            wssPort: config.port,
            forceTLS: config.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }

    return instance;
}
