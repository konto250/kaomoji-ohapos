/**
 * 顔文字コピペアプリのロジック
 */
$(function () {
    // DOM要素
    const $button1 = $('#actionBtn1');
    const $button2 = $('#actionBtn2');
    const $button3 = $('#actionBtn3');
    const $messageElement = $('#message');
    const $kaomojiDisplay = $('#kaomojiDisplay');
    const $prefixInput1 = $('#prefix1');
    const $prefixInput2 = $('#prefix2');
    const $prefixInput3 = $('#prefix3');

    let kaomojis = [];

    // 1. JSONファイルから顔文字を読み込む
    $.getJSON('kaomoji.json', function (data) {
        if (Array.isArray(data.kaomojis)) {
            kaomojis = data.kaomojis;
        } else if (data.kaomojis && typeof data.kaomojis === 'object') {
            kaomojis = Object.values(data.kaomojis).flat().filter(item => typeof item === 'string');
        } else {
            kaomojis = [];
        }
        console.log(`${kaomojis.length}個の顔文字を読み込みました。`);
        // 初期メッセージをクリア
        $messageElement.text('ボタンを押してね');
    }).fail(function () {
        console.error('kaomoji.jsonの読み込みに失敗しました。');
        $messageElement.text('エラー: kaomoji.jsonが読み込めません。');
        $button1.prop('disabled', true); // ボタンを無効化
        $button2.prop('disabled', true); // ボタンを無効化
        $button3.prop('disabled', true); // ボタンを無効化
    });

    // ボタンクリック時の共通処理
    function handleButtonClick(prefix) {
        if (kaomojis.length === 0) {
            return;
        }

        // 3. ランダムな顔文字と定型文を取得
        const randomIndex = Math.floor(Math.random() * kaomojis.length);
        const randomKaomoji = kaomojis[randomIndex];

        // 4. 文字列を結合 (接頭辞の前後の空白・改行を削除し、半角スペースを追加)
        const selectedHonorific = $('input[name="honorific"]:checked').val() || '';
        let processedPrefix = prefix.replace(/ちゃん|さん/g, '');
        if (selectedHonorific) {
            processedPrefix = processedPrefix.replace(/\r?\n+$/, '');
            processedPrefix = `${selectedHonorific}\n${processedPrefix}`;
        }
        const finalText = `${processedPrefix.trim()} ${randomKaomoji}`;

        // 5. クリップボードにコピー (Firefox対応)
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(finalText).then(() => {
                showSuccess(finalText);
            }).catch(err => {
                console.error('クリップボードへのコピーに失敗しました (API): ', err);
                fallbackCopyTextToClipboard(finalText);
            });
        } else {
            fallbackCopyTextToClipboard(finalText);
        }
    }

    // コピー成功時の表示処理
    function showSuccess(text) {
        $kaomojiDisplay.text(text);
        $messageElement.text('クリップボードにコピーしました！');
        $messageElement.css('color', '#2ecc71'); // Success color
        setTimeout(() => {
            $messageElement.css('color', '#555');
        }, 1500);
    }

    // コピー失敗時の表示処理
    function showError() {
        $messageElement.text('コピーに失敗しました…');
        $messageElement.css('color', '#e74c3c'); // Error color
        setTimeout(() => {
            $messageElement.css('color', '#555');
        }, 2000);
    }

    // フォールバック用のコピー処理
    function fallbackCopyTextToClipboard(text) {
        const textArea = document.createElement("textarea");
        textArea.value = text;

        // スクロールバーが表示されないようにスタイルを設定
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";

        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showSuccess(text);
            } else {
                showError();
                console.error('フォールバックコピーコマンドの実行に失敗しました。');
            }
        } catch (err) {
            showError();
            console.error('フォールバックコピー中にエラーが発生しました: ', err);
        }

        document.body.removeChild(textArea);
    }

    // 各ボタンにイベントリスナーを設定
    $button1.on('click', function () {
        handleButtonClick($prefixInput1.val() || '');
    });

    $button2.on('click', function () {
        handleButtonClick($prefixInput2.val() || '');
    });

    $button3.on('click', function () {
        handleButtonClick($prefixInput3.val() || '');
    });

    console.log('Application initialized.');
});