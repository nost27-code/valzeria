const loadImage = (source) => new Promise((resolve) => {
    if (!source) return resolve(null);

    const image = new Image();
    image.crossOrigin = 'anonymous';
    image.onload = () => resolve(image);
    image.onerror = () => resolve(null);
    image.src = source;
});

const extractBackgroundUrl = (node) => {
    const hero = node.querySelector('.adventurer-card-hero');
    const background = hero ? window.getComputedStyle(hero).getPropertyValue('--adventurer-card-bg') : '';
    return background.match(/url\(["']?(.*?)["']?\)/)?.[1] ?? null;
};

const drawContainImage = (context, image, x, y, width, height) => {
    const scale = Math.min(width / image.width, height / image.height);
    const drawWidth = image.width * scale;
    const drawHeight = image.height * scale;
    context.drawImage(image, x + (width - drawWidth) / 2, y + (height - drawHeight) / 2, drawWidth, drawHeight);
};

const drawCoverImage = (context, image, x, y, width, height) => {
    const scale = Math.max(width / image.width, height / image.height);
    const drawWidth = image.width * scale;
    const drawHeight = image.height * scale;
    context.drawImage(image, x + (width - drawWidth) / 2, y + (height - drawHeight) / 2, drawWidth, drawHeight);
};

const roundedRect = (context, x, y, width, height, radius) => {
    const safeRadius = Math.min(radius, width / 2, height / 2);
    context.beginPath();
    context.moveTo(x + safeRadius, y);
    context.arcTo(x + width, y, x + width, y + height, safeRadius);
    context.arcTo(x + width, y + height, x, y + height, safeRadius);
    context.arcTo(x, y + height, x, y, safeRadius);
    context.arcTo(x, y, x + width, y, safeRadius);
    context.closePath();
};

const canvasToBlob = (canvas) => new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

window.adventurerCardToBlob = async (node, player) => {
    const canvas = document.createElement('canvas');
    canvas.width = 1080;
    canvas.height = 1080;

    const context = canvas.getContext('2d');
    const weapons = (player.favorite_weapons ?? []).slice(0, 3);
    const valmons = (player.valmon_badges ?? []).filter((valmon) => valmon.owned).slice(0, 14);
    const [backgroundImage, avatarImage, ...itemImages] = await Promise.all([
        loadImage(extractBackgroundUrl(node)),
        loadImage(node.querySelector('img[alt="アバター"]')?.currentSrc),
        ...weapons.map((weapon) => loadImage(weapon.image)),
        ...valmons.map((valmon) => loadImage(valmon.image)),
    ]);
    const weaponImages = itemImages.slice(0, weapons.length);
    const valmonImages = itemImages.slice(weapons.length);

    context.fillStyle = '#101b31';
    context.fillRect(0, 0, 1080, 1080);
    if (backgroundImage) {
        context.save();
        context.globalAlpha = 0.62;
        drawCoverImage(context, backgroundImage, 0, 0, 1080, 1080);
        context.restore();
    }
    const overlay = context.createLinearGradient(0, 0, 0, 1080);
    overlay.addColorStop(0, 'rgba(8,18,37,0.98)');
    overlay.addColorStop(0.55, 'rgba(13,30,54,0.78)');
    overlay.addColorStop(1, 'rgba(5,14,29,0.96)');
    context.fillStyle = overlay;
    context.fillRect(0, 0, 1080, 1080);

    context.strokeStyle = '#d9b454';
    context.lineWidth = 8;
    roundedRect(context, 16, 16, 1048, 1048, 28);
    context.stroke();
    context.fillStyle = '#f8e7ad';
    context.font = '700 24px sans-serif';
    context.fillText('VALZERIA ADVENTURER CARD', 58, 62);
    context.textAlign = 'right';
    context.fillStyle = '#ffffff';
    context.font = '700 18px sans-serif';
    context.fillText('#ヴァルゼリアの冒険者', 1022, 62);
    context.textAlign = 'left';

    roundedRect(context, 64, 106, 236, 236, 18);
    context.fillStyle = '#fff3c9';
    context.fill();
    context.strokeStyle = '#f2d579';
    context.lineWidth = 5;
    context.stroke();
    context.save();
    roundedRect(context, 72, 114, 220, 220, 13);
    context.clip();
    if (avatarImage) drawContainImage(context, avatarImage, 80, 122, 204, 204);
    context.restore();

    context.fillStyle = '#ffffff';
    context.font = '700 58px sans-serif';
    context.fillText(player.name ?? '冒険者', 340, 160);
    context.fillStyle = '#d9e7fa';
    context.font = '700 29px sans-serif';
    context.fillText(`Lv.${player.level ?? 1}  /  ${player.job ?? ''}`, 344, 208);
    roundedRect(context, 340, 238, 300, 58, 28);
    context.fillStyle = 'rgba(206,157,43,0.22)';
    context.fill();
    context.strokeStyle = '#f0c654';
    context.lineWidth = 3;
    context.stroke();
    context.fillStyle = '#ffe8a3';
    context.font = '700 24px sans-serif';
    context.fillText(`戦力  ${Number(player.power ?? 0).toLocaleString()}`, 372, 277);
    context.fillStyle = '#f5cf70';
    context.font = '700 23px sans-serif';
    context.fillText(player.equipped_title ?? '', 344, 332);

    roundedRect(context, 64, 376, 952, 118, 16);
    context.fillStyle = 'rgba(5,14,29,0.72)';
    context.fill();
    context.strokeStyle = 'rgba(239,204,105,0.9)';
    context.lineWidth = 2;
    context.stroke();
    context.fillStyle = '#f7db8b';
    context.font = '700 20px sans-serif';
    context.fillText('一言コメント', 94, 414);
    context.fillStyle = '#ffffff';
    context.font = '600 28px sans-serif';
    const comment = String(player.profile_comment ?? 'よろしくお願いします');
    context.fillText(comment.length > 38 ? `${comment.slice(0, 38)}…` : comment, 94, 462);

    roundedRect(context, 64, 530, 952, 430, 18);
    context.fillStyle = 'rgba(7,17,33,0.78)';
    context.fill();
    context.strokeStyle = '#d8b35a';
    context.lineWidth = 2;
    context.stroke();
    context.fillStyle = '#f6e6b6';
    context.font = '700 26px sans-serif';
    context.fillText('お気に入り武器', 94, 578);
    context.fillStyle = '#d7bd67';
    context.font = '600 16px sans-serif';
    context.fillText('WEAPON COLLECTION', 94, 602);

    weapons.forEach((weapon, index) => {
        const x = 110 + index * 308;
        const image = weaponImages[index];
        roundedRect(context, x, 632, 264, 280, 14);
        context.fillStyle = 'rgba(247,250,255,0.96)';
        context.fill();
        context.strokeStyle = weapon.quality?.border_color ?? '#d1b46c';
        context.lineWidth = 4;
        context.stroke();
        if (image) drawContainImage(context, image, x + 28, 650, 208, 154);
        context.textAlign = 'center';
        context.fillStyle = weapon.rank_color ?? '#42556d';
        context.font = '700 25px sans-serif';
        context.fillText(weapon.rank ?? '', x + 132, 832);
        context.fillStyle = '#273a55';
        context.font = '700 17px sans-serif';
        context.fillText(String(weapon.name ?? '').slice(0, 15), x + 132, 864);
        context.fillStyle = '#8b5d12';
        context.font = '700 19px sans-serif';
        context.fillText(`+${weapon.enhance_level ?? 0}`, x + 132, 894);
        context.textAlign = 'left';
    });
    if (weapons.length === 0) {
        context.fillStyle = '#cbd5e1';
        context.font = '700 18px sans-serif';
        context.fillText('お気に入り武器を登録するとここに飾られます', 94, 730);
    }

    roundedRect(context, 64, 980, 952, 60, 16);
    context.fillStyle = 'rgba(7,17,33,0.76)';
    context.fill();
    context.strokeStyle = '#94b27e';
    context.lineWidth = 2;
    context.stroke();
    context.fillStyle = '#d7efbd';
    context.font = '700 18px sans-serif';
    context.fillText(`ヴァルモン  ${valmons.length}/21`, 86, 1017);
    valmons.forEach((valmon, index) => {
        const x = 320 + index * 44;
        const y = 992;
        const image = valmonImages[index];
        context.fillStyle = 'rgba(255,255,255,0.84)';
        context.beginPath();
        context.arc(x, y + 18, 18, 0, Math.PI * 2);
        context.fill();
        if (image) drawContainImage(context, image, x - 16, y + 2, 32, 32);
        if (valmon.is_partner) {
            context.fillStyle = '#d49a17';
            context.font = '700 15px sans-serif';
            context.fillText('★', x + 16, y + 3);
        }
    });
    if (valmons.length === 0) {
        context.fillStyle = '#cbd5e1';
        context.font = '600 17px sans-serif';
        context.fillText('仲間にしたヴァルモンがここに並びます', 320, 1017);
    }
    return canvasToBlob(canvas);
};
