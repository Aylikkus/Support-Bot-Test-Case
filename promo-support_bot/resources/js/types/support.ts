export type SenderType = 'user' | 'bot' | 'operator';

export type MessageType = 'text' | 'image' | 'file';

export type ChatMessage = {
    id: number;
    sender_type: SenderType;
    message_type: MessageType;
    text: string | null;
    image_url: string | null;
    file: { name: string; url: string } | null;
    created_at: string;
};

export type QueueItem = {
    id: number;
    user_id: number;
    created_at: string;
    messages_count: number;
    last_message: ChatMessage | null;
};

export type SelectedRequest = {
    id: number;
    user_id: number;
    status: 'open' | 'closed';
    created_at: string;
    closed_at: string | null;
    messages: ChatMessage[];
};

export type SupportStats = {
    bot_closed_count: number;
    forwarded_count: number;
    avg_operator_response_time: string;
};

export type ReverbConfig = {
    key: string | null;
    host: string;
    port: number;
    scheme: 'http' | 'https';
};
