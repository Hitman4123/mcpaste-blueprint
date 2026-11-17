import React, { useEffect, useRef, useState } from 'react';
import http from '@/api/http';
import { ServerContext } from '@/state/server';
import { SocketEvent } from '@/components/server/events';
import { Button } from '@/components/elements/button/index';
import { Dialog } from '@/components/elements/dialog';

/*
 * "Share log" in the console's power-button row.
 *
 * The console's own lines are collected here as they arrive over the
 * server's websocket, the same feed the terminal draws, including the
 * history Wings replays when the console connects. Up to the last 1000
 * lines go to this panel's /api/client/extensions/mcpaste route, which
 * checks access, attaches the server's details and posts to MCPaste with
 * the panel's token. The browser never sees that token: it only gets the
 * paste link back.
 *
 * Note for editors: Blueprint replaces placeholders written as a word in
 * curly braces, such as the extension's identifier or display title, in
 * every file. Don't put such a word alone inside JSX braces here.
 */

const MAX_LINES = 1000;
const ROUTE = '/api/client/extensions/mcpaste';

type Shared = { url: string; expires: number | null };

const errorText = (error: any): string =>
    error?.response?.data?.error ?? (error?.response ? `The panel answered with HTTP ${error.response.status}.` : 'The panel didn\'t answer.');

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const connected = ServerContext.useStoreState((state) => state.socket.connected);
    const socket = ServerContext.useStoreState((state) => state.socket.instance);

    const lines = useRef<string[]>([]);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [hideIps, setHideIps] = useState(true);
    const [shared, setShared] = useState<Shared | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [destination, setDestination] = useState<string | null>(null);
    const [lineCount, setLineCount] = useState(0);

    // Collect console output. The terminal clears and asks Wings for the
    // log history whenever the socket (re)connects, so start over then too.
    useEffect(() => {
        if (!connected || !socket) return;

        lines.current = [];
        const onOutput = (output: string) => {
            for (const line of output.split(/\r?\n/)) lines.current.push(line);
            const extra = lines.current.length - MAX_LINES;
            if (extra > 0) lines.current.splice(0, extra);
        };

        socket.addListener(SocketEvent.CONSOLE_OUTPUT, onOutput);
        return () => {
            socket.removeListener(SocketEvent.CONSOLE_OUTPUT, onOutput);
        };
    }, [connected, socket]);

    const start = () => {
        setShared(null);
        setProblem(null);
        setLineCount(lines.current.length);
        setOpen(true);

        http.get(`${ROUTE}/status`)
            .then(({ data }) => {
                setDestination(data.domain ?? null);
                if (!data.configured) setProblem('MCPaste isn\'t set up on this panel yet. Ask an admin to add an API key under Admin → Extensions → MCPaste.');
            })
            .catch(() => setDestination(null));
    };

    const share = () => {
        setBusy(true);
        setProblem(null);

        http.post(`${ROUTE}/servers/${uuid}/share`, { lines: lines.current.join('\n'), hideIps })
            .then(({ data }) => setShared({ url: data.url, expires: data.expires ?? null }))
            .catch((error) => setProblem(errorText(error)))
            .then(() => setBusy(false));
    };

    const copy = () => {
        if (!shared) return;
        navigator.clipboard?.writeText(shared.url).catch(() => undefined);
    };

    return (
        <>
            <Button.Text className={'flex-1'} style={{ whiteSpace: 'nowrap' }} onClick={start} disabled={!connected}>
                Share log
            </Button.Text>

            <Dialog open={open} onClose={() => setOpen(false)} title={shared ? 'Log shared' : 'Share this console'}>
                {shared ? (
                    <div>
                        <p className={'text-sm text-neutral-300'}>
                            Anyone with this link can read the log. MCPaste has already looked for known problems.
                        </p>
                        <div className={'mt-4 flex gap-2'}>
                            <input
                                className={'flex-1 rounded bg-neutral-900 px-3 py-2 font-mono text-sm text-neutral-100'}
                                value={shared.url}
                                readOnly
                                onFocus={(event) => event.currentTarget.select()}
                            />
                            <Button onClick={copy}>Copy</Button>
                        </div>
                        <a className={'mt-3 inline-block text-sm text-blue-400 underline'} href={shared.url} target={'_blank'} rel={'noopener noreferrer'}>
                            Open it
                        </a>
                    </div>
                ) : (
                    <div className={'text-sm text-neutral-300'}>
                        <p>
                            Sends the last {lineCount === 1 ? '1 line' : `${lineCount} lines`} of this console
                            {destination ? ` to ${destination}` : ' to MCPaste'}, with the server's node, egg, image and resource use on top.
                            Anyone with the link can read it.
                        </p>
                        {lineCount === 0 && <p className={'mt-2 text-yellow-400'}>Nothing has come through the console yet.</p>}
                        <label className={'mt-4 flex items-center gap-2'}>
                            <input type={'checkbox'} checked={hideIps} onChange={(event) => setHideIps(event.currentTarget.checked)} />
                            Hide IP addresses
                        </label>
                    </div>
                )}
                {problem && <p className={'mt-4 text-sm text-red-400'}>{problem}</p>}

                <Dialog.Footer>
                    <Button.Text onClick={() => setOpen(false)}>{shared ? 'Close' : 'Cancel'}</Button.Text>
                    {!shared && (
                        <Button onClick={share} disabled={busy || lineCount === 0}>
                            {busy ? 'Sharing…' : 'Share'}
                        </Button>
                    )}
                </Dialog.Footer>
            </Dialog>
        </>
    );
};
