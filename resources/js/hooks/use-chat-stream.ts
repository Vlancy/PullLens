import { useCallback, useState } from 'react';

export interface ChatMessage {
    role: 'user' | 'assistant';
    content: string;
}

interface UseChatStreamReturn {
    messages: ChatMessage[];
    isLoading: boolean;
    isQuerying: boolean;
    error: string | null;
    sendMessage: (text: string) => Promise<void>;
    clearMessages: () => Promise<void>;
}

export function useChatStream(
    chatUrl: string,
    clearUrl: string,
    initialMessages: ChatMessage[] = [],
    providerId: string | null = null,
): UseChatStreamReturn {
    const [messages, setMessages] = useState<ChatMessage[]>(initialMessages);
    const [isLoading, setIsLoading] = useState(false);
    const [isQuerying, setIsQuerying] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const sendMessage = useCallback(
        async (text: string) => {
            const history = [...messages];

            setMessages((prev) => [...prev, { role: 'user', content: text }, { role: 'assistant', content: '' }]);
            setIsLoading(true);
            setIsQuerying(false);
            setError(null);

            const abort = new AbortController();
            const timeoutId = setTimeout(() => abort.abort(), 30_000);

            try {
                const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

                const response = await fetch(chatUrl, {
                    method: 'POST',
                    signal: abort.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        Accept: 'text/event-stream',
                    },
                    body: JSON.stringify({ message: text, history, provider_id: providerId }),
                });

                if (!response.ok || !response.body) {
                    throw new Error(`Request failed: ${response.status}`);
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let done = false;

                while (!done) {
                    const chunk = await reader.read();
                    done = chunk.done;
                    if (chunk.value) {
                        buffer += decoder.decode(chunk.value, { stream: true });
                    }

                    const lines = buffer.split('\n');
                    buffer = lines.pop() ?? '';

                    for (const line of lines) {
                        if (!line.startsWith('data: ')) continue;
                        const raw = line.slice(6).trim();
                        if (raw === '[DONE]') {
                            done = true;
                            break;
                        }

                        let event: Record<string, unknown>;
                        try {
                            event = JSON.parse(raw) as Record<string, unknown>;
                        } catch {
                            continue; // ignore malformed lines only
                        }

                        if (event.type === 'text_delta') {
                            setMessages((prev) => {
                                const next = [...prev];
                                const last = next[next.length - 1];
                                if (last?.role === 'assistant') {
                                    next[next.length - 1] = { ...last, content: last.content + (event.delta as string) };
                                }
                                return next;
                            });
                        } else if (event.type === 'tool_call') {
                            setIsQuerying(true);
                        } else if (event.type === 'tool_result') {
                            setIsQuerying(false);
                        } else if (event.type === 'error') {
                            throw new Error(event.message as string);
                        }
                    }
                }
            } catch (e) {
                const msg = e instanceof Error && e.name === 'AbortError'
                    ? 'The AI provider did not respond in time. Check your provider settings.'
                    : e instanceof Error ? e.message : 'Something went wrong';
                setError(msg);
                setMessages((prev) => {
                    const next = [...prev];
                    if (next[next.length - 1]?.role === 'assistant' && next[next.length - 1]?.content === '') {
                        next.pop();
                    }
                    return next;
                });
            } finally {
                clearTimeout(timeoutId);
                setIsLoading(false);
                setIsQuerying(false);
            }
        },
        [messages, chatUrl, providerId],
    );

    const clearMessages = useCallback(async () => {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        await fetch(clearUrl, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf },
        });
        setMessages([]);
        setError(null);
    }, [clearUrl]);

    return { messages, isLoading, isQuerying, error, sendMessage, clearMessages };
}
