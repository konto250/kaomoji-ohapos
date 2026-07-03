import './globals.css';

export const metadata = {
    title: 'ランダム顔文字コピー',
    description: 'ランダム顔文字を接頭辞付きでコピーするアプリ'
};

export default function RootLayout({ children }) {
    return (
        <html lang="ja">
            <body>{children}</body>
        </html>
    );
}
