// 元画像を変更せず、確認済みのアイコンだけ表示用に補正する。
// 他のアイコンへ無条件に適用すると、輪郭に接する白い衣装も消してしまう。
export function cleanCourierBackground(data, width, height) {
    const result = new Uint8ClampedArray(data);
    const seen = new Uint8Array(width * height);
    const queue = [];
    const visit = index => {
        if (seen[index]) return;
        const offset = index * 4;
        const [r, g, b, a] = data.subarray(offset, offset + 4);
        const neutralBackground = Math.min(r, g, b) >= 105 && Math.max(r, g, b) - Math.min(r, g, b) <= 22;
        if (a !== 0 && !neutralBackground) return;
        seen[index] = 1;
        queue.push(index);
        result[offset + 3] = 0;
    };
    // 透明部分を起点にする。輪郭で囲まれた目・荷物の白い部分は残す。
    for (let i = 0; i < width * height; i++) {
        if (data[i * 4 + 3] === 0) visit(i);
    }
    for (let head = 0; head < queue.length; head++) {
        const i = queue[head], x = i % width, y = Math.floor(i / width);
        if (x > 0) visit(i - 1);
        if (x + 1 < width) visit(i + 1);
        if (y > 0) visit(i - width);
        if (y + 1 < height) visit(i + width);
    }
    return result;
}

export function preparePlayerIcon(image, url) {
    if (!/(?:^|\/)images\/chara\/chara_008\.webp(?:\?|$)/.test(url)) return image;
    // この旧素材の寸法と異なる画像には補正を適用しない。
    if (image.width !== 96 || image.height !== 96) return image;
    try {
        const canvas = document.createElement('canvas');
        canvas.width = image.width;
        canvas.height = image.height;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(image, 0, 0);
        const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height);
        pixels.data.set(cleanCourierBackground(pixels.data, canvas.width, canvas.height));
        ctx.putImageData(pixels, 0, 0);
        return canvas;
    } catch {
        // CORS等で読めなくてもキャラクター自体は表示する。
        return image;
    }
}
