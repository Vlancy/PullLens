import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Bot,
    Database,
    RotateCcw,
    Send,
    Sparkles,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useChatStream } from '@/hooks/use-chat-stream';
import type { ChatMessage } from '@/hooks/use-chat-stream';

const SUGGESTED_QUESTIONS = [
    'Who is the most productive developer this month?',
    'What is the team overview for the last 30 days?',
    'Which repository has the lowest health score?',
    'How many low-effort commits were made this week?',
];

interface AiProvider {
    id: string;
    name: string;
    is_default: boolean;
}

interface Props {
    history: ChatMessage[];
    providers: AiProvider[];
    default_provider_id: string | null;
}

export default function Assistant({
    history,
    providers,
    default_provider_id,
}: Props) {
    const [selectedProviderId, setSelectedProviderId] = useState<string | null>(
        default_provider_id,
    );

    const {
        messages,
        isLoading,
        isQuerying,
        error,
        sendMessage,
        clearMessages,
    } = useChatStream(
        '/assistant/chat',
        '/assistant/history',
        history,
        selectedProviderId,
    );
    const [input, setInput] = useState('');
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, isQuerying]);

    const handleSend = async () => {
        const text = input.trim();

        if (!text || isLoading) {
            return;
        }

        setInput('');
        await sendMessage(text);
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void handleSend();
        }
    };

    const handleSuggestedQuestion = async (question: string) => {
        if (isLoading) {
            return;
        }

        setInput('');
        await sendMessage(question);
    };

    const isEmpty = messages.length === 0;
    const providerMissing =
        providers.length === 0 || selectedProviderId === null;

    return (
        <>
            <Head title="Assistant" />

            <div className="flex h-full flex-col">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 md:px-6 md:py-4">
                    <div className="flex items-center gap-2">
                        <Bot className="h-5 w-5 text-primary" />
                        <h1 className="font-semibold">PullLens Assistant</h1>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                        {providers.length > 0 && (
                            <Select
                                value={selectedProviderId ?? ''}
                                onValueChange={setSelectedProviderId}
                            >
                                <SelectTrigger className="h-8 w-40 text-sm sm:w-48">
                                    <SelectValue placeholder="Select provider" />
                                </SelectTrigger>
                                <SelectContent>
                                    {providers.map((p) => (
                                        <SelectItem key={p.id} value={p.id}>
                                            {p.name}
                                            {p.is_default && (
                                                <span className="ml-1 text-xs text-muted-foreground">
                                                    (default)
                                                </span>
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}

                        {!isEmpty && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => void clearMessages()}
                                className="gap-1.5 text-muted-foreground"
                            >
                                <RotateCcw className="h-3.5 w-3.5" />
                                New conversation
                            </Button>
                        )}
                    </div>
                </div>

                {/* Provider warning banner */}
                {providerMissing && (
                    <div className="flex items-center gap-3 border-b bg-amber-50 px-4 py-3 text-sm text-amber-800 md:px-6 dark:bg-amber-950/30 dark:text-amber-400">
                        <AlertTriangle className="h-4 w-4 shrink-0" />
                        <span>
                            {providers.length === 0
                                ? 'No AI provider configured.'
                                : 'No AI provider selected.'}{' '}
                            <Link
                                href="/settings/ai-providers"
                                className="font-medium underline underline-offset-2"
                            >
                                Go to AI Provider settings
                            </Link>{' '}
                            to add or fix your provider credentials.
                        </span>
                    </div>
                )}

                {/* Messages */}
                <div className="flex-1 overflow-y-auto px-4 py-6">
                    {isEmpty ? (
                        <div className="flex h-full flex-col items-center justify-center gap-6">
                            <div className="flex flex-col items-center gap-3 text-center">
                                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
                                    <Sparkles className="h-7 w-7 text-primary" />
                                </div>
                                <div>
                                    <p className="font-medium">
                                        Ask about your engineering metrics
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        I can query live data from PullLens to
                                        answer questions about developers, PRs,
                                        and code quality.
                                    </p>
                                </div>
                            </div>
                            {!providerMissing && (
                                <div className="grid w-full max-w-lg gap-2">
                                    {SUGGESTED_QUESTIONS.map((q) => (
                                        <button
                                            key={q}
                                            onClick={() =>
                                                void handleSuggestedQuestion(q)
                                            }
                                            className="rounded-lg border border-border px-4 py-2.5 text-left text-sm transition-colors hover:bg-muted/50"
                                        >
                                            {q}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="mx-auto flex max-w-3xl flex-col gap-4">
                            {messages.map((msg, i) => (
                                <div
                                    key={i}
                                    className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                                >
                                    {msg.role === 'assistant' && (
                                        <div className="mt-0.5 mr-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                            <Bot className="h-4 w-4 text-primary" />
                                        </div>
                                    )}
                                    <div
                                        className={`max-w-[80%] rounded-2xl px-4 py-2.5 text-sm ${
                                            msg.role === 'user'
                                                ? 'rounded-br-sm bg-primary text-primary-foreground'
                                                : 'rounded-bl-sm bg-muted'
                                        }`}
                                    >
                                        {msg.content === '' &&
                                        msg.role === 'assistant' ? (
                                            <span className="flex gap-1">
                                                <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:0ms]" />
                                                <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:150ms]" />
                                                <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:300ms]" />
                                            </span>
                                        ) : msg.role === 'assistant' ? (
                                            <ReactMarkdown
                                                components={{
                                                    p: ({ children }) => (
                                                        <p className="mb-2 last:mb-0">
                                                            {children}
                                                        </p>
                                                    ),
                                                    strong: ({ children }) => (
                                                        <strong className="font-semibold">
                                                            {children}
                                                        </strong>
                                                    ),
                                                    em: ({ children }) => (
                                                        <em className="italic">
                                                            {children}
                                                        </em>
                                                    ),
                                                    ul: ({ children }) => (
                                                        <ul className="mb-2 ml-4 list-disc space-y-0.5">
                                                            {children}
                                                        </ul>
                                                    ),
                                                    ol: ({ children }) => (
                                                        <ol className="mb-2 ml-4 list-decimal space-y-0.5">
                                                            {children}
                                                        </ol>
                                                    ),
                                                    li: ({ children }) => (
                                                        <li className="leading-relaxed">
                                                            {children}
                                                        </li>
                                                    ),
                                                    code: ({
                                                        children,
                                                        className,
                                                    }) =>
                                                        className ? (
                                                            <code className="block overflow-x-auto rounded bg-black/10 p-2 font-mono text-xs dark:bg-white/10">
                                                                {children}
                                                            </code>
                                                        ) : (
                                                            <code className="rounded bg-black/10 px-1 py-0.5 font-mono text-xs dark:bg-white/10">
                                                                {children}
                                                            </code>
                                                        ),
                                                    pre: ({ children }) => (
                                                        <pre className="mb-2 overflow-x-auto">
                                                            {children}
                                                        </pre>
                                                    ),
                                                    h1: ({ children }) => (
                                                        <h1 className="mb-1 text-base font-bold">
                                                            {children}
                                                        </h1>
                                                    ),
                                                    h2: ({ children }) => (
                                                        <h2 className="mb-1 text-sm font-bold">
                                                            {children}
                                                        </h2>
                                                    ),
                                                    h3: ({ children }) => (
                                                        <h3 className="mb-1 text-sm font-semibold">
                                                            {children}
                                                        </h3>
                                                    ),
                                                    blockquote: ({
                                                        children,
                                                    }) => (
                                                        <blockquote className="border-l-2 border-current pl-3 opacity-70">
                                                            {children}
                                                        </blockquote>
                                                    ),
                                                    hr: () => (
                                                        <hr className="my-2 border-current opacity-20" />
                                                    ),
                                                }}
                                            >
                                                {msg.content}
                                            </ReactMarkdown>
                                        ) : (
                                            <span className="whitespace-pre-wrap">
                                                {msg.content}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            ))}

                            {isQuerying && (
                                <div className="flex justify-start">
                                    <div className="mt-0.5 mr-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                        <Database className="h-4 w-4 text-primary" />
                                    </div>
                                    <div className="flex items-center gap-2 rounded-2xl rounded-bl-sm bg-muted px-4 py-2.5 text-sm">
                                        <span className="text-muted-foreground">
                                            Querying data
                                        </span>
                                        <span className="flex gap-1">
                                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:0ms]" />
                                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:150ms]" />
                                            <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:300ms]" />
                                        </span>
                                    </div>
                                </div>
                            )}

                            {error && (
                                <div className="rounded-lg bg-destructive/10 px-4 py-2.5 text-sm text-destructive">
                                    {error}
                                </div>
                            )}

                            <div ref={messagesEndRef} />
                        </div>
                    )}
                </div>

                {/* Input */}
                <div className="border-t px-4 py-4">
                    <div className="mx-auto max-w-3xl">
                        <div className="flex items-end gap-2 rounded-xl border border-input bg-background px-3 py-2 focus-within:ring-1 focus-within:ring-ring">
                            <textarea
                                ref={textareaRef}
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                onKeyDown={handleKeyDown}
                                placeholder={
                                    providerMissing
                                        ? 'Configure an AI provider to start chatting…'
                                        : 'Ask about developer efficiency, PR metrics, code quality...'
                                }
                                className="max-h-36 min-h-[2.5rem] w-full resize-none border-0 bg-transparent p-0 text-sm outline-none placeholder:text-gray-400 disabled:opacity-50"
                                rows={1}
                                disabled={isLoading || providerMissing}
                            />
                            <Button
                                size="icon"
                                onClick={() => void handleSend()}
                                disabled={
                                    !input.trim() ||
                                    isLoading ||
                                    providerMissing
                                }
                                className="mb-0.5 shrink-0"
                            >
                                <Send className="h-4 w-4" />
                            </Button>
                        </div>
                        <p className="mt-2 text-center text-xs text-muted-foreground">
                            Press Enter to send · Shift+Enter for new line
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}

Assistant.layout = {
    breadcrumbs: [{ title: 'Assistant', href: '/assistant' }],
};
