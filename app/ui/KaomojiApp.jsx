'use client';

import { useEffect, useMemo, useState } from 'react';

const defaultPrefixes = {
    prefix1: 'おはよー',
    prefix2: 'おはようございます',
    prefix3: 'おはありです'
};

function normalizeKaomojiData(data) {
    if (!data || !data.kaomojis) {
        return {};
    }

    if (Array.isArray(data.kaomojis)) {
        return { 普通: data.kaomojis };
    }

    if (typeof data.kaomojis === 'object') {
        return data.kaomojis;
    }

    return {};
}

function getRandomItem(items) {
    if (items.length === 0) {
        return null;
    }

    return items[Math.floor(Math.random() * items.length)];
}

export default function KaomojiApp() {
    const [prefix1, setPrefix1] = useState(defaultPrefixes.prefix1);
    const [prefix2, setPrefix2] = useState(defaultPrefixes.prefix2);
    const [prefix3, setPrefix3] = useState(defaultPrefixes.prefix3);
    const [honorific, setHonorific] = useState('さん');
    const [category, setCategory] = useState('all');
    const [kaomojiMap, setKaomojiMap] = useState({});
    const [displayText, setDisplayText] = useState('(・∀・)');
    const [message, setMessage] = useState('読み込み中...');

    useEffect(() => {
        let isMounted = true;

        async function loadKaomojis() {
            try {
                const response = await fetch('/api/kaomoji', { cache: 'no-store' });
                if (!response.ok) {
                    throw new Error('顔文字データの取得に失敗しました。');
                }

                const data = await response.json();
                const normalized = normalizeKaomojiData(data);

                if (isMounted) {
                    setKaomojiMap(normalized);
                    setMessage('ボタンを押してね');
                }
            } catch (error) {
                if (isMounted) {
                    setMessage('エラー: kaomoji.jsonが読み込めません。');
                }
            }
        }

        loadKaomojis();

        return () => {
            isMounted = false;
        };
    }, []);

    const categories = useMemo(() => Object.keys(kaomojiMap), [kaomojiMap]);

    const kaomojiPool = useMemo(() => {
        if (category === 'all') {
            return Object.values(kaomojiMap).flat();
        }

        return kaomojiMap[category] || [];
    }, [category, kaomojiMap]);

    const isReady = kaomojiPool.length > 0;

    async function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.top = '0';
        textarea.style.left = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        const copied = document.execCommand('copy');
        document.body.removeChild(textarea);

        if (!copied) {
            throw new Error('フォールバックコピーに失敗しました。');
        }
    }

    async function handleCopy(prefix) {
        if (!isReady) {
            return;
        }

        const randomKaomoji = getRandomItem(kaomojiPool);
        if (!randomKaomoji) {
            setMessage('顔文字が見つかりません。');
            return;
        }

        let processedPrefix = prefix.replace(/ちゃん|さん/g, '');
        if (honorific) {
            processedPrefix = processedPrefix.replace(/\r?\n+$/, '');
            processedPrefix = `${honorific}\n${processedPrefix}`;
        }

        const finalText = `${processedPrefix.trim()} ${randomKaomoji}`;

        try {
            await copyText(finalText);
            setDisplayText(finalText);
            setMessage('クリップボードにコピーしました！');
        } catch {
            setMessage('コピーに失敗しました…');
        }
    }

    return (
        <main id="app">
            <h1>ランダム顔文字コピー</h1>
            <p>ボタンを押すと、ランダムな顔文字がクリップボードにコピーされます。</p>

            <section className="card">
                <div className="option-row">
                    <span className="option-label">カテゴリ:</span>
                    <select value={category} onChange={(e) => setCategory(e.target.value)}>
                        <option value="all">すべて</option>
                        {categories.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="option-row">
                    <span className="option-label">敬称:</span>
                    <label>
                        <input
                            type="radio"
                            name="honorific"
                            value=""
                            checked={honorific === ''}
                            onChange={(e) => setHonorific(e.target.value)}
                        />
                        敬称略
                    </label>
                    <label>
                        <input
                            type="radio"
                            name="honorific"
                            value="ちゃん"
                            checked={honorific === 'ちゃん'}
                            onChange={(e) => setHonorific(e.target.value)}
                        />
                        ちゃん
                    </label>
                    <label>
                        <input
                            type="radio"
                            name="honorific"
                            value="さん"
                            checked={honorific === 'さん'}
                            onChange={(e) => setHonorific(e.target.value)}
                        />
                        さん
                    </label>
                </div>

                <div className="action-group">
                    <label htmlFor="prefix1">接頭辞1:</label>
                    <textarea
                        id="prefix1"
                        rows={2}
                        placeholder="例: お疲れ様です"
                        value={prefix1}
                        onChange={(e) => setPrefix1(e.target.value)}
                    />
                    <button type="button" onClick={() => handleCopy(prefix1)} disabled={!isReady}>
                        接頭辞1でコピー
                    </button>
                </div>

                <div className="action-group">
                    <label htmlFor="prefix2">接頭辞2:</label>
                    <textarea
                        id="prefix2"
                        rows={2}
                        placeholder="例: こんにちは"
                        value={prefix2}
                        onChange={(e) => setPrefix2(e.target.value)}
                    />
                    <button type="button" onClick={() => handleCopy(prefix2)} disabled={!isReady}>
                        接頭辞2でコピー
                    </button>
                </div>

                <div className="action-group">
                    <label htmlFor="prefix3">接頭辞3:</label>
                    <textarea
                        id="prefix3"
                        rows={2}
                        placeholder="例: ありがとう"
                        value={prefix3}
                        onChange={(e) => setPrefix3(e.target.value)}
                    />
                    <button type="button" onClick={() => handleCopy(prefix3)} disabled={!isReady}>
                        接頭辞3でコピー
                    </button>
                </div>

                <div id="kaomojiDisplay">{displayText}</div>
                <p id="message">{message}</p>
            </section>
        </main>
    );
}
