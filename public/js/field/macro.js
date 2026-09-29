// マクロ地図（public/field/valzeria-macro.png）。1セル = map_scale マス。
//   R = 地形帯 / G = 標高 x25 / B = 海岸からの距離（128 + セル x 6。陸が正）

export class MacroMap {
    constructor(width, height, pixels) {
        this.width = width;
        this.height = height;
        const n = width * height;
        this.biome = new Uint8Array(n);
        this.heightField = new Float32Array(n);
        this.coast = new Float32Array(n);
        for (let i = 0; i < n; i++) {
            this.biome[i] = pixels[i * 4];
            this.heightField[i] = pixels[i * 4 + 1] / 25;
            this.coast[i] = (pixels[i * 4 + 2] - 128) / 6;
        }
    }

    static async load(url) {
        // decode() は裏のタブで待たされることがあるので onload で待つ
        const image = await new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error(`マクロ地図を読み込めません: ${url}`));
            img.src = url;
        });
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const ctx = canvas.getContext('2d', { willReadFrequently: true, colorSpace: 'srgb' });
        ctx.drawImage(image, 0, 0);
        const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        return new MacroMap(canvas.width, canvas.height, data);
    }

    clampIndex(x, y) {
        const cx = Math.max(0, Math.min(this.width - 1, x));
        const cy = Math.max(0, Math.min(this.height - 1, y));
        return cy * this.width + cx;
    }

    // セル座標（小数。セルの中心は i + 0.5）
    biomeAt(mx, my) {
        if (mx < 0 || my < 0 || mx >= this.width || my >= this.height) return 0;
        return this.biome[this.clampIndex(Math.floor(mx), Math.floor(my))];
    }

    bilinear(field, mx, my, outside) {
        const x = mx - 0.5;
        const y = my - 0.5;
        if (x < -1 || y < -1 || x > this.width || y > this.height) return outside;
        const x0 = Math.floor(x);
        const y0 = Math.floor(y);
        const fx = x - x0;
        const fy = y - y0;
        const get = (xx, yy) => (xx < 0 || yy < 0 || xx >= this.width || yy >= this.height ? outside : field[yy * this.width + xx]);
        const a = get(x0, y0);
        const b = get(x0 + 1, y0);
        const c = get(x0, y0 + 1);
        const d = get(x0 + 1, y0 + 1);
        return (a + (b - a) * fx) * (1 - fy) + (c + (d - c) * fx) * fy;
    }

    heightAt(mx, my) {
        return this.bilinear(this.heightField, mx, my, 0);
    }

    // 海岸からの符号付き距離（セル）。陸が正
    coastAt(mx, my) {
        return this.bilinear(this.coast, mx, my, -21);
    }
}
