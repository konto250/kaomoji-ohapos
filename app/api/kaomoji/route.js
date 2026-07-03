import { readFile } from 'node:fs/promises';
import { join } from 'node:path';

export async function GET() {
    try {
        const filePath = join(process.cwd(), 'kaomoji.json');
        const file = await readFile(filePath, 'utf-8');
        const data = JSON.parse(file);

        return Response.json(data);
    } catch (error) {
        return Response.json(
            { message: 'kaomoji.jsonの読み込みに失敗しました。', detail: String(error) },
            { status: 500 }
        );
    }
}
