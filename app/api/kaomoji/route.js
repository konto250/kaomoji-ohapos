import data from '../../../kaomoji.json';

export async function GET() {
    return Response.json(data);
}
