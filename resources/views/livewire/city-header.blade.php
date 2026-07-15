<div x-data="{ 
          isPlayerModalOpen: @entangle('isPlayerModalOpen'), 
          playerInfo: @entangle('playerInfo'),
          playersExpanded: false,
          notificationOpen: false,
          selectedJobBadgeTier: null,
          selectedJobBadge: null,
     }">
    <style>
        .profile-frame-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
            width: 92%;
            max-width: 420px;
            max-height: 88vh;
            overflow-y: auto;
            isolation: isolate;
            border-radius: var(--profile-radius, 18px);
            border-width: 0 !important;
            outline: none;
            scrollbar-color: rgba(100, 116, 139, .56) rgba(241, 245, 249, .72);
            scrollbar-width: thin;
        }
        .profile-frame-modal::-webkit-scrollbar {
            width: 10px;
        }
        .profile-frame-modal::-webkit-scrollbar-track {
            margin: 14px 0;
            border-radius: 999px;
            background: rgba(241, 245, 249, .72);
        }
        .profile-frame-modal::-webkit-scrollbar-thumb {
            border: 2px solid rgba(241, 245, 249, .72);
            border-radius: 999px;
            background: linear-gradient(180deg, rgba(148, 163, 184, .82), rgba(71, 85, 105, .72));
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .34);
        }
        .profile-frame-modal::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, rgba(100, 116, 139, .92), rgba(51, 65, 85, .86));
        }
        .profile-frame-standard {
            --profile-main: #b88a09;
            --profile-main-soft: #f8e7a7;
            --profile-main-dark: #7c4f00;
            --profile-inner-border: rgba(184, 134, 11, .35);
            --profile-radius: 18px;
            --profile-inner-radius: 12px;
            --profile-panel-radius: 12px;
            --profile-avatar-radius: 24px;
            --profile-pattern: radial-gradient(circle at 16px 16px, rgba(212,175,55,.12) 0 1.5px, transparent 2px);
            --profile-ornament: radial-gradient(circle, rgba(212, 175, 55, .28) 0 2px, transparent 3px 100%);
            --profile-title-bg: linear-gradient(135deg, rgba(255, 251, 235, .96), rgba(255, 255, 255, .82));
            border-color: #d4af37;
            background:
                linear-gradient(90deg, rgba(212, 175, 55, .18) 0 4px, transparent 4px calc(100% - 4px), rgba(212, 175, 55, .18) calc(100% - 4px)),
                linear-gradient(180deg, #fff 0%, #fffaf0 42%, #fff 100%);
            box-shadow: 0 18px 44px rgba(15, 23, 42, .28), inset 0 0 0 1px rgba(212, 175, 55, .20);
        }
        .profile-frame-arclea {
            --profile-main: #d4af37;
            --profile-main-soft: #fef3c7;
            --profile-main-dark: #744210;
            --profile-inner-border: rgba(212, 175, 55, .48);
            --profile-radius: 22px;
            --profile-inner-radius: 16px;
            --profile-panel-radius: 14px;
            --profile-avatar-radius: 20px;
            --profile-pattern: linear-gradient(135deg, rgba(212,175,55,.10) 0 12px, transparent 12px 24px);
            --profile-ornament: conic-gradient(from 45deg, rgba(212,175,55,.45), transparent 18%, rgba(255,255,255,.55) 26%, transparent 42%, rgba(180,83,9,.24) 62%, transparent 80%, rgba(212,175,55,.45));
            --profile-title-bg: linear-gradient(135deg, rgba(255, 251, 235, .98), rgba(255,255,255,.88));
            border-color: #d4af37;
            background:
                linear-gradient(180deg, rgba(212, 175, 55, .30) 0 6px, transparent 6px),
                radial-gradient(circle at 12% 14%, rgba(251, 191, 36, .26), transparent 30%),
                radial-gradient(circle at 88% 18%, rgba(252, 211, 77, .20), transparent 28%),
                linear-gradient(180deg, #fffdf5 0%, #fff 48%, #fffbeb 100%);
            box-shadow: 0 18px 44px rgba(120, 86, 8, .26), inset 0 0 0 1px rgba(212,175,55,.24);
        }
        .profile-frame-marine {
            --profile-main: #0284c7;
            --profile-main-soft: #bae6fd;
            --profile-main-dark: #075985;
            --profile-inner-border: rgba(14, 165, 233, .42);
            --profile-radius: 28px 14px 28px 14px;
            --profile-inner-radius: 22px 10px 22px 10px;
            --profile-panel-radius: 18px 8px 18px 8px;
            --profile-avatar-radius: 50% 42% 50% 42%;
            --profile-pattern: radial-gradient(circle at 14px 18px, rgba(14,165,233,.16) 0 4px, transparent 5px),
                radial-gradient(circle at 42px 36px, rgba(34,211,238,.12) 0 7px, transparent 8px);
            --profile-ornament: radial-gradient(circle at 22% 22%, rgba(56, 189, 248, .45) 0 4px, transparent 5px),
                radial-gradient(circle at 64% 52%, rgba(34, 211, 238, .30) 0 7px, transparent 8px),
                radial-gradient(circle at 36% 78%, rgba(14, 165, 233, .22) 0 5px, transparent 6px);
            --profile-title-bg: linear-gradient(135deg, rgba(224, 242, 254, .98), rgba(255, 255, 255, .86));
            border-color: #38bdf8;
            background:
                linear-gradient(180deg, rgba(14, 165, 233, .22) 0 6px, transparent 6px),
                radial-gradient(circle at 8% 11%, rgba(125, 211, 252, .45) 0 28px, transparent 29px),
                radial-gradient(circle at 94% 17%, rgba(34, 211, 238, .28) 0 34px, transparent 35px),
                linear-gradient(180deg, #f0fbff 0%, #fff 42%, #eefcff 100%);
            box-shadow: 0 18px 44px rgba(8, 47, 73, .30), inset 0 0 0 1px rgba(14, 165, 233, .22);
        }
        .profile-frame-elphia {
            --profile-main: #16a34a;
            --profile-main-soft: #bbf7d0;
            --profile-main-dark: #166534;
            --profile-inner-border: rgba(34, 197, 94, .42);
            --profile-radius: 24px 24px 12px 24px;
            --profile-inner-radius: 18px 18px 8px 18px;
            --profile-panel-radius: 18px 18px 8px 18px;
            --profile-avatar-radius: 34% 58% 42% 56%;
            --profile-pattern: radial-gradient(ellipse at 18px 14px, rgba(34,197,94,.14) 0 8px, transparent 9px),
                radial-gradient(ellipse at 44px 38px, rgba(132,204,22,.10) 0 10px, transparent 11px);
            --profile-ornament: radial-gradient(ellipse at 40% 30%, rgba(34,197,94,.38) 0 18%, transparent 20%),
                radial-gradient(ellipse at 68% 64%, rgba(132,204,22,.28) 0 22%, transparent 24%);
            --profile-title-bg: linear-gradient(135deg, rgba(236, 253, 245, .98), rgba(255,255,255,.86));
            border-color: #4ade80;
            background:
                linear-gradient(180deg, rgba(34, 197, 94, .22) 0 6px, transparent 6px),
                radial-gradient(circle at 10% 14%, rgba(134, 239, 172, .35), transparent 30%),
                radial-gradient(circle at 92% 22%, rgba(190, 242, 100, .24), transparent 28%),
                linear-gradient(180deg, #f0fdf4 0%, #fff 42%, #ecfdf5 100%);
            box-shadow: 0 18px 44px rgba(20, 83, 45, .24), inset 0 0 0 1px rgba(34,197,94,.20);
        }
        .profile-frame-granberg {
            --profile-main: #475569;
            --profile-main-soft: #fed7aa;
            --profile-main-dark: #1e293b;
            --profile-inner-border: rgba(71, 85, 105, .44);
            --profile-radius: 8px;
            --profile-inner-radius: 4px;
            --profile-panel-radius: 6px;
            --profile-avatar-radius: 10px;
            --profile-pattern: repeating-linear-gradient(90deg, rgba(71,85,105,.10) 0 6px, transparent 6px 14px),
                repeating-linear-gradient(0deg, rgba(249,115,22,.08) 0 2px, transparent 2px 18px);
            --profile-ornament: repeating-linear-gradient(45deg, rgba(71,85,105,.30) 0 7px, transparent 7px 14px),
                radial-gradient(circle at 50% 50%, rgba(249,115,22,.26), transparent 34%);
            --profile-title-bg: linear-gradient(135deg, rgba(248,250,252,.98), rgba(255,247,237,.84));
            border-color: #64748b;
            background:
                linear-gradient(180deg, rgba(71, 85, 105, .28) 0 6px, transparent 6px),
                radial-gradient(circle at 14% 14%, rgba(251, 146, 60, .25), transparent 30%),
                linear-gradient(180deg, #f8fafc 0%, #fff 44%, #fff7ed 100%);
            box-shadow: 0 18px 44px rgba(30, 41, 59, .28), inset 0 0 0 1px rgba(71,85,105,.22);
        }
        .profile-frame-frostria {
            --profile-main: #2563eb;
            --profile-main-soft: #dbeafe;
            --profile-main-dark: #1e3a8a;
            --profile-inner-border: rgba(147, 197, 253, .48);
            --profile-radius: 18px 34px 18px 34px;
            --profile-inner-radius: 12px 28px 12px 28px;
            --profile-panel-radius: 10px 24px 10px 24px;
            --profile-avatar-radius: 28px 10px 28px 10px;
            --profile-pattern: linear-gradient(135deg, rgba(147,197,253,.14) 0 10px, transparent 10px 22px),
                linear-gradient(45deg, rgba(219,234,254,.22) 0 8px, transparent 8px 18px);
            --profile-ornament: conic-gradient(from 45deg, rgba(147, 197, 253, .34), transparent 18%, rgba(219, 234, 254, .44) 30%, transparent 46%, rgba(96, 165, 250, .25) 62%, transparent 78%, rgba(147, 197, 253, .34));
            --profile-title-bg: linear-gradient(135deg, rgba(239, 246, 255, .98), rgba(255, 255, 255, .86));
            border-color: #93c5fd;
            background:
                linear-gradient(180deg, rgba(147, 197, 253, .25) 0 6px, transparent 6px),
                linear-gradient(135deg, rgba(191, 219, 254, .28) 0 16%, transparent 16% 34%, rgba(226, 232, 240, .34) 34% 48%, transparent 48%),
                linear-gradient(180deg, #f8fbff 0%, #fff 45%, #eff6ff 100%);
            box-shadow: 0 18px 44px rgba(30, 58, 138, .25), inset 0 0 0 1px rgba(147, 197, 253, .28);
        }
        .profile-frame-sandra {
            --profile-main: #ea580c;
            --profile-main-soft: #fed7aa;
            --profile-main-dark: #9a3412;
            --profile-inner-border: rgba(249, 115, 22, .42);
            --profile-radius: 10px 26px 10px 26px;
            --profile-inner-radius: 6px 20px 6px 20px;
            --profile-panel-radius: 8px 20px 8px 20px;
            --profile-avatar-radius: 14px 30px 14px 30px;
            --profile-pattern: repeating-linear-gradient(12deg, rgba(234,88,12,.10) 0 4px, transparent 4px 16px),
                radial-gradient(circle at 70% 20%, rgba(245,158,11,.18), transparent 28%);
            --profile-ornament: radial-gradient(circle at 30% 36%, rgba(251,146,60,.28), transparent 34%),
                repeating-conic-gradient(from 14deg, rgba(234,88,12,.20) 0 10deg, transparent 10deg 20deg);
            --profile-title-bg: linear-gradient(135deg, rgba(255,247,237,.98), rgba(255,255,255,.86));
            border-color: #fb923c;
            background:
                linear-gradient(180deg, rgba(249, 115, 22, .25) 0 6px, transparent 6px),
                radial-gradient(circle at 12% 16%, rgba(253, 186, 116, .34), transparent 30%),
                linear-gradient(180deg, #fff7ed 0%, #fff 44%, #fffbeb 100%);
            box-shadow: 0 18px 44px rgba(154, 52, 18, .24), inset 0 0 0 1px rgba(249,115,22,.20);
        }
        .profile-frame-luminous {
            --profile-main: #7c3aed;
            --profile-main-soft: #ddd6fe;
            --profile-main-dark: #4c1d95;
            --profile-inner-border: rgba(124, 58, 237, .42);
            --profile-radius: 18px 18px 30px 30px;
            --profile-inner-radius: 12px 12px 24px 24px;
            --profile-panel-radius: 12px 12px 22px 22px;
            --profile-avatar-radius: 50% 18px 50% 18px;
            --profile-pattern: radial-gradient(circle at 18px 18px, rgba(124,58,237,.15) 0 3px, transparent 4px),
                radial-gradient(circle at 44px 34px, rgba(99,102,241,.12) 0 5px, transparent 6px);
            --profile-ornament: radial-gradient(circle at 30% 30%, rgba(167,139,250,.45) 0 4px, transparent 5px),
                radial-gradient(circle at 68% 62%, rgba(99,102,241,.28) 0 8px, transparent 9px),
                conic-gradient(from 45deg, transparent, rgba(124,58,237,.18), transparent);
            --profile-title-bg: linear-gradient(135deg, rgba(245,243,255,.98), rgba(255,255,255,.86));
            border-color: #a78bfa;
            background:
                linear-gradient(180deg, rgba(124, 58, 237, .24) 0 6px, transparent 6px),
                radial-gradient(circle at 14% 16%, rgba(196, 181, 253, .34), transparent 30%),
                radial-gradient(circle at 92% 22%, rgba(129, 140, 248, .22), transparent 28%),
                linear-gradient(180deg, #f5f3ff 0%, #fff 44%, #eef2ff 100%);
            box-shadow: 0 18px 44px rgba(76, 29, 149, .25), inset 0 0 0 1px rgba(124,58,237,.20);
        }
        .profile-frame-necrom {
            --profile-main: #6d28d9;
            --profile-main-soft: #e9d5ff;
            --profile-main-dark: #312e81;
            --profile-inner-border: rgba(88, 28, 135, .44);
            --profile-radius: 6px 22px 6px 22px;
            --profile-inner-radius: 3px 16px 3px 16px;
            --profile-panel-radius: 5px 16px 5px 16px;
            --profile-avatar-radius: 12px 34px 12px 34px;
            --profile-pattern: radial-gradient(circle at 24px 22px, rgba(88,28,135,.16), transparent 18px),
                repeating-linear-gradient(145deg, rgba(15,23,42,.08) 0 5px, transparent 5px 18px);
            --profile-ornament: radial-gradient(circle at 28% 34%, rgba(168,85,247,.36), transparent 30%),
                radial-gradient(circle at 62% 66%, rgba(20,184,166,.18), transparent 26%);
            --profile-title-bg: linear-gradient(135deg, rgba(245,243,255,.96), rgba(255,255,255,.84));
            border-color: #7e22ce;
            background:
                linear-gradient(180deg, rgba(88, 28, 135, .30) 0 6px, transparent 6px),
                radial-gradient(circle at 10% 14%, rgba(168, 85, 247, .25), transparent 30%),
                linear-gradient(180deg, #faf5ff 0%, #fff 44%, #f8fafc 100%);
            box-shadow: 0 18px 44px rgba(49, 46, 129, .28), inset 0 0 0 1px rgba(88,28,135,.20);
        }
        .profile-frame-celestia {
            --profile-main: #4f46e5;
            --profile-main-soft: #e0e7ff;
            --profile-main-dark: #3730a3;
            --profile-inner-border: rgba(99, 102, 241, .40);
            --profile-radius: 32px;
            --profile-inner-radius: 26px;
            --profile-panel-radius: 24px;
            --profile-avatar-radius: 38% 38% 18px 18px;
            --profile-pattern: radial-gradient(circle at 18px 18px, rgba(99,102,241,.14) 0 2px, transparent 3px),
                radial-gradient(circle at 48px 30px, rgba(125,211,252,.14) 0 4px, transparent 5px);
            --profile-ornament: conic-gradient(from 0deg, rgba(99,102,241,.26), transparent 22%, rgba(125,211,252,.28) 34%, transparent 52%, rgba(255,255,255,.52) 66%, transparent 84%, rgba(99,102,241,.26));
            --profile-title-bg: linear-gradient(135deg, rgba(238,242,255,.98), rgba(255,255,255,.88));
            border-color: #818cf8;
            background:
                linear-gradient(180deg, rgba(99, 102, 241, .22) 0 6px, transparent 6px),
                radial-gradient(circle at 14% 16%, rgba(125, 211, 252, .28), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(199, 210, 254, .32), transparent 30%),
                linear-gradient(180deg, #eef2ff 0%, #fff 44%, #f0f9ff 100%);
            box-shadow: 0 18px 44px rgba(55, 48, 163, .23), inset 0 0 0 1px rgba(99,102,241,.20);
        }
        .profile-frame-valzeria {
            --profile-main: #be123c;
            --profile-main-soft: #fecdd3;
            --profile-main-dark: #881337;
            --profile-inner-border: rgba(190, 18, 60, .45);
            --profile-radius: 4px;
            --profile-inner-radius: 2px;
            --profile-panel-radius: 4px;
            --profile-avatar-radius: 6px;
            --profile-pattern: repeating-linear-gradient(135deg, rgba(190,18,60,.10) 0 6px, transparent 6px 18px),
                radial-gradient(circle at 82% 18%, rgba(15,23,42,.14), transparent 28%);
            --profile-ornament: conic-gradient(from 35deg, rgba(190,18,60,.36), transparent 20%, rgba(15,23,42,.28) 34%, transparent 52%, rgba(244,63,94,.24) 68%, transparent 84%, rgba(190,18,60,.36));
            --profile-title-bg: linear-gradient(135deg, rgba(255,241,242,.98), rgba(255,255,255,.86));
            border-color: #be123c;
            background:
                linear-gradient(180deg, rgba(190, 18, 60, .28) 0 6px, transparent 6px),
                radial-gradient(circle at 12% 16%, rgba(244, 63, 94, .25), transparent 30%),
                linear-gradient(180deg, #fff1f2 0%, #fff 44%, #f8fafc 100%);
            box-shadow: 0 18px 44px rgba(76, 5, 25, .28), inset 0 0 0 1px rgba(190,18,60,.20);
        }
        .profile-frame-modal .profile-accent-border {
            border-color: var(--profile-inner-border, #e5e7eb) !important;
        }
        .profile-frame-modal .profile-accent-text {
            color: var(--profile-main-dark, #1e293b) !important;
        }
        .profile-close-button {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 3;
            color: color-mix(in srgb, var(--profile-main, #64748b) 62%, #64748b);
            background: rgba(255, 255, 255, .78);
            border: 1px solid var(--profile-inner-border, #e5e7eb);
            border-radius: 999px;
            padding: 4px;
            box-shadow: 0 6px 14px rgba(15, 23, 42, .10);
        }
        .profile-hero {
            position: relative;
            margin: 0 -2px 12px;
            padding: 14px 38px 14px 12px;
            border: 2px solid var(--profile-inner-border, rgba(226, 232, 240, .8));
            border-radius: var(--profile-panel-radius, 16px);
            background: var(--profile-title-bg);
            overflow: hidden;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.70), 0 10px 22px rgba(15,23,42,.08);
        }
        .profile-hero::after {
            content: "";
            position: absolute;
            inset: auto 0 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--profile-main, #d4af37), transparent);
            opacity: .82;
        }
        .profile-avatar-frame {
            position: relative;
            display: flex;
            width: 82px;
            height: 82px;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: var(--profile-avatar-radius, 24px);
            background: rgba(255, 255, 255, .72);
            border: 2px solid var(--profile-inner-border, rgba(226, 232, 240, .82));
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.74), 0 12px 26px rgba(15, 23, 42, .14);
        }
        .profile-avatar-frame::before,
        .profile-avatar-frame::after {
            content: "";
            position: absolute;
            inset: 9px;
            border-radius: inherit;
            border: 1px solid color-mix(in srgb, var(--profile-main, #d4af37) 52%, transparent);
            opacity: .72;
        }
        .profile-avatar-frame img {
            filter: drop-shadow(0 5px 8px rgba(15, 23, 42, .18));
        }
        .profile-avatar-icon {
            position: relative;
            z-index: 1;
            max-width: 74%;
            max-height: 74%;
        }
        .profile-name {
            color: var(--profile-main-dark, #1e293b);
            text-shadow: 0 1px 0 rgba(255, 255, 255, .82);
        }
        .profile-mini-card {
            border-color: var(--profile-inner-border, #e5e7eb) !important;
            background: rgba(255, 255, 255, .72) !important;
            border-radius: var(--profile-panel-radius, 12px) !important;
        }
        .profile-rank-card {
            border-color: color-mix(in srgb, var(--profile-main, #d4af37) 58%, #fff) !important;
            background: linear-gradient(135deg, color-mix(in srgb, var(--profile-main-soft, #fef3c7) 64%, #fff), rgba(255,255,255,.92)) !important;
            border-radius: var(--profile-panel-radius, 12px) !important;
        }
        .profile-section-panel {
            border-color: var(--profile-inner-border, #e5e7eb) !important;
            background: rgba(255, 255, 255, .76) !important;
            border-radius: var(--profile-panel-radius, 12px) !important;
        }
        .adventurer-card-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            z-index: 9999;
            width: min(92vw, 430px);
            max-height: 92dvh;
            overflow-x: hidden;
            overflow-y: auto;
            transform: translate(-50%, -50%);
            border-radius: 22px;
            background: linear-gradient(180deg, #fffdf7 0%, #fffaf0 100%);
            border: 1px solid rgba(199, 157, 64, .52);
            box-shadow: 0 24px 54px rgba(15, 23, 42, .34), inset 0 0 0 1px rgba(255, 255, 255, .78);
            color: #2f2415;
            scrollbar-width: thin;
            scrollbar-color: rgba(180, 133, 38, .62) rgba(255, 248, 232, .72);
        }
        .adventurer-card-inner {
            position: relative;
            padding: 22px 12px 18px;
        }
        .adventurer-card-inner.is-support-pass-card {
            background:
                linear-gradient(90deg, rgba(120,53,15,.10), transparent 18%, transparent 82%, rgba(120,53,15,.10)),
                linear-gradient(180deg, rgba(254,243,199,.28), rgba(255,255,255,0) 18%, rgba(254,243,199,.24));
        }
        .adventurer-card-hero {
            position: relative;
            overflow: visible;
            min-height: 365px;
            border-radius: 16px;
            border: 0;
            background:
                linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.10)),
                var(--adventurer-card-bg, url('{{ asset('images/profile/adventurer_card_bg01.webp') }}')) center / cover no-repeat,
                linear-gradient(135deg, #eef7ff 0%, #f8faf0 56%, #fef3c7 100%);
            box-shadow: 0 8px 18px rgba(92, 64, 17, .12);
        }
        .adventurer-card-hero::before {
            content: "";
            position: absolute;
            inset: 9px;
            z-index: 1;
            border-radius: 12px;
            background: linear-gradient(90deg, rgba(255,255,255,.74) 0%, rgba(255,255,255,.52) 42%, rgba(255,255,255,.20) 100%);
            pointer-events: none;
        }
        .adventurer-card-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 3;
            background: var(--adventurer-card-frame, url('{{ asset('images/profile/adventurer_card_frame01.webp') }}')) center / 124% 124% no-repeat;
            pointer-events: none;
        }
        .adventurer-card-hero.is-support-pass {
            background:
                linear-gradient(180deg, rgba(120,53,15,.42) 0 46px, transparent 96px 100%),
                linear-gradient(135deg, rgba(255,255,255,.82) 0%, rgba(254,243,199,.60) 34%, rgba(15,23,42,.26) 100%),
                repeating-linear-gradient(135deg, rgba(146,64,14,.16) 0 1px, transparent 1px 9px),
                var(--adventurer-card-bg, url('{{ asset('images/profile/adventurer_card_bg01.webp') }}')) center / cover no-repeat,
                linear-gradient(135deg, #fff7ed 0%, #fef3c7 48%, #dbeafe 100%);
            box-shadow:
                0 22px 46px rgba(15, 23, 42, .32),
                0 0 0 2px rgba(245, 158, 11, .54),
                0 0 0 6px rgba(120, 53, 15, .10),
                inset 0 0 0 1px rgba(255, 255, 255, .72),
                inset 0 0 42px rgba(245, 158, 11, .24);
        }
        .adventurer-card-hero.is-support-pass::before {
            inset: 8px;
            border: 1px solid rgba(180, 83, 9, .24);
            background:
                linear-gradient(90deg, rgba(255,255,255,.82) 0%, rgba(254,243,199,.55) 48%, rgba(120,53,15,.10) 100%);
            box-shadow:
                inset 0 0 0 1px rgba(255, 255, 255, .60),
                inset 0 18px 34px rgba(255, 255, 255, .30);
        }
        .adventurer-card-hero.is-support-pass::after {
            filter: drop-shadow(0 0 5px rgba(245, 158, 11, .55));
        }
        .adventurer-card-pass-seal {
            position: absolute;
            right: 34px;
            top: 76px;
            z-index: 4;
            display: grid;
            width: 54px;
            height: 54px;
            place-items: center;
            filter: drop-shadow(0 8px 12px rgba(92, 64, 17, .24));
        }
        .adventurer-card-pass-seal img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-avatar {
            top: 96px;
            background: linear-gradient(180deg, rgba(255,251,235,.96), rgba(255,247,237,.78));
            box-shadow:
                0 14px 26px rgba(92, 64, 17, .18),
                0 0 0 1px rgba(245, 158, 11, .32),
                inset 0 0 18px rgba(255, 255, 255, .55);
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-title {
            padding-top: 28px;
            min-height: 138px;
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-title h3 {
            color: #713f12;
            text-shadow:
                0 2px 0 rgba(255, 255, 255, .78),
                0 8px 18px rgba(146, 64, 14, .18);
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-title p {
            color: #334155;
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-power-pill {
            border-color: rgba(245, 158, 11, .56);
            background: linear-gradient(180deg, rgba(255,255,255,.90), rgba(254,243,199,.86));
            color: #78350f;
            box-shadow:
                0 8px 18px rgba(146, 64, 14, .14),
                inset 0 0 0 1px rgba(255, 255, 255, .72);
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-vital-bar {
            border-color: rgba(245, 158, 11, .32);
            background: rgba(255, 251, 235, .76);
            box-shadow:
                inset 0 1px 2px rgba(15,23,42,.14),
                0 1px 0 rgba(255,255,255,.66);
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-badge {
            border-color: rgba(245, 158, 11, .38);
            background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(254,243,199,.78));
            box-shadow:
                0 8px 18px rgba(92, 64, 17, .12),
                inset 0 0 0 1px rgba(255, 255, 255, .62);
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-badge:nth-child(odd) {
            background: linear-gradient(180deg, rgba(255,251,235,.94), rgba(255,237,213,.82));
        }
        .adventurer-card-pass-record {
            border-color: rgba(245, 158, 11, .34) !important;
            background: linear-gradient(135deg, rgba(255,251,235,.96), rgba(254,243,199,.72)) !important;
            box-shadow:
                0 8px 16px rgba(146, 64, 14, .10),
                inset 0 0 0 1px rgba(255, 255, 255, .70) !important;
        }
        .adventurer-card-avatar {
            position: absolute;
            left: 42px;
            top: 98px;
            z-index: 2;
            display: grid;
            width: 100px;
            height: 100px;
            place-items: center;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(255,255,255,.92), rgba(255,246,218,.68));
            box-shadow: 0 12px 22px rgba(88, 58, 10, .16);
        }
        .adventurer-card-avatar::after {
            content: "";
            position: absolute;
            inset: -22px;
            z-index: 2;
            background: var(--adventurer-avatar-frame, url('{{ asset('images/profile/adventurer_avatar_frame01.webp') }}')) center / contain no-repeat;
            pointer-events: none;
        }
        .adventurer-card-avatar img {
            position: relative;
            z-index: 1;
            max-width: 68%;
            max-height: 68%;
            object-fit: contain;
            filter: drop-shadow(0 6px 8px rgba(15, 23, 42, .24));
        }
        .adventurer-card-title {
            position: relative;
            z-index: 2;
            display: flex;
            min-height: 132px;
            flex-direction: column;
            align-items: center;
            padding: 28px 40px 0;
            text-align: center;
        }
        .adventurer-card-title-line {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: center;
            max-width: 100%;
            gap: 10px;
        }
        .adventurer-card-title h3 {
            color: #6b3f08;
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 28px;
            font-weight: 800;
            line-height: 1.08;
            letter-spacing: 0;
            overflow-wrap: anywhere;
            word-break: keep-all;
            text-shadow: 0 2px 0 rgba(255,255,255,.72);
        }
        .adventurer-card-title p {
            color: #475569;
            font-size: 17px;
            font-weight: 900;
            line-height: 1.1;
            white-space: nowrap;
        }
        .adventurer-card-equipped-title {
            display: block;
            max-width: 100%;
            margin: 0 0 2px;
            padding: 0;
            border: 0;
            background: transparent;
            color: #8a5a0d;
            font-size: 13px;
            font-weight: 900;
            line-height: 1.15;
            text-align: left;
            text-shadow: 0 1px 0 rgba(255, 255, 255, .70);
            box-shadow: none;
        }
        .adventurer-card-equipped-title span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .adventurer-card-power-pill {
            display: inline-flex;
            align-items: baseline;
            gap: 5px;
            margin-top: 7px;
            border-radius: 999px;
            border: 1px solid rgba(180, 128, 29, .38);
            background: rgba(255,255,255,.86);
            padding: 5px 15px;
            color: #6b3f08;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 4px 10px rgba(92, 64, 17, .08);
        }
        .adventurer-card-vitals {
            position: relative;
            z-index: 2;
            display: grid;
            gap: 6px;
            margin: -2px 36px 0 164px;
        }
        .adventurer-card-vital-row {
            display: grid;
            grid-template-columns: 2.1rem 1fr auto;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 900;
        }
        .adventurer-card-vital-bar {
            height: 7px;
            overflow: hidden;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, .28);
            background: rgba(226, 232, 240, .78);
            box-shadow: inset 0 1px 2px rgba(15,23,42,.12);
        }
        .adventurer-card-vital-fill {
            height: 100%;
            border-radius: inherit;
        }
        .adventurer-card-badges {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 5px;
            padding: 16px 54px 18px;
        }
        .adventurer-card-hero.is-support-pass .adventurer-card-badges,
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-badges {
            padding-top: 40px;
            padding-bottom: 12px;
        }
        .adventurer-card-badge {
            min-height: 48px;
            border-radius: 9px;
            border: 1px solid rgba(201, 153, 50, .28);
            background: rgba(255,255,255,.76);
            box-shadow: 0 5px 12px rgba(92, 64, 17, .08);
        }
        .adventurer-card-badge-icon {
            width: 20px;
            height: 20px;
            object-fit: contain;
            filter: drop-shadow(0 2px 3px rgba(15,23,42,.18));
        }
        .adventurer-card-comment {
            position: relative;
            z-index: 2;
            margin: 0 60px 20px;
            padding: 0 4px;
        }
        .adventurer-card-comment-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            color: #8a5a0d;
            font-size: 10px;
            font-weight: 900;
            line-height: 1;
        }
        .adventurer-card-comment-edit {
            flex-shrink: 0;
            border-radius: 999px;
            border: 1px solid rgba(212, 175, 55, .54);
            background: rgba(255,255,255,.72);
            padding: 3px 8px;
            color: #8a5a0d;
            font-size: 10px;
            font-weight: 900;
            line-height: 1;
        }
        .adventurer-card-comment-edit:hover {
            background: rgba(255,251,235,.95);
        }
        .adventurer-card-comment-text {
            margin-top: 5px;
            max-height: 38px;
            overflow: hidden;
            color: #1e293b;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.45;
            white-space: pre-line;
        }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-comment-head,
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-comment-edit,
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-equipped-title {
            color: #1e3a8a;
        }
        .adventurer-card-medals {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            padding: 18px 72px 20px;
            text-align: center;
        }
        .adventurer-card-medal-icon {
            display: grid;
            width: 56px;
            height: 56px;
            margin: 0 auto 6px;
            place-items: center;
            border-radius: 999px;
            border: 1px solid rgba(201, 153, 50, .44);
            background: rgba(255,255,255,.78);
            box-shadow: 0 5px 12px rgba(92, 64, 17, .10);
        }
        .adventurer-card-section {
            margin-top: 14px;
            border-radius: 14px;
            border: 1px solid rgba(201, 153, 50, .32);
            background: rgba(255, 255, 255, .74);
            box-shadow: 0 6px 16px rgba(92, 64, 17, .08);
        }
        .adventurer-card-inner.is-support-pass-card .adventurer-card-section {
            position: relative;
            overflow: hidden;
            border-color: rgba(245, 158, 11, .44);
            background:
                linear-gradient(180deg, rgba(255,255,255,.94), rgba(255,251,235,.86)),
                repeating-linear-gradient(135deg, rgba(180,83,9,.08) 0 1px, transparent 1px 10px);
            box-shadow:
                0 12px 24px rgba(92, 64, 17, .14),
                inset 0 0 0 1px rgba(255, 255, 255, .72);
        }
        .adventurer-card-inner.is-support-pass-card .adventurer-card-section::before {
            content: "";
            position: absolute;
            inset: 0;
            border-top: 4px solid rgba(180, 83, 9, .58);
            border-bottom: 1px solid rgba(245, 158, 11, .25);
            pointer-events: none;
        }
        .adventurer-card-inner.is-support-pass-card .adventurer-card-section > div:first-child {
            position: relative;
            color: #78350f !important;
            text-shadow: 0 1px 0 rgba(255, 255, 255, .78);
        }
        .adventurer-card-inner.is-support-pass-card .adventurer-card-section .grid > div,
        .adventurer-card-inner.is-support-pass-card .adventurer-card-section .flex.min-h-10 {
            border-color: rgba(245, 158, 11, .28) !important;
            background: linear-gradient(180deg, rgba(255,255,255,.94), rgba(255,247,237,.88)) !important;
            box-shadow:
                0 6px 12px rgba(92, 64, 17, .08),
                inset 0 0 0 1px rgba(255,255,255,.66) !important;
        }
        .adventurer-card-inner.is-support-pass-card .adventurer-card-section .text-green-600 {
            color: #059669 !important;
            text-shadow: 0 1px 0 rgba(255, 255, 255, .60);
        }
        .adventurer-card-inner.is-support-pass-blue-gold-card {
            background:
                linear-gradient(90deg, rgba(30,64,175,.14), transparent 18%, transparent 82%, rgba(14,165,233,.12)),
                linear-gradient(180deg, rgba(219,234,254,.38), rgba(255,255,255,0) 18%, rgba(224,242,254,.24));
        }
        .adventurer-card-hero.is-support-pass-blue-gold {
            background:
                linear-gradient(180deg, rgba(30,64,175,.48) 0 46px, transparent 96px 100%),
                linear-gradient(135deg, rgba(239,246,255,.88) 0%, rgba(191,219,254,.58) 40%, rgba(56,189,248,.24) 100%),
                repeating-linear-gradient(135deg, rgba(30,64,175,.13) 0 1px, transparent 1px 9px),
                var(--adventurer-card-bg, url('{{ asset('images/profile/adventurer_card_bg01.webp') }}')) center / cover no-repeat,
                linear-gradient(135deg, #eff6ff 0%, #dbeafe 54%, #e0f2fe 100%);
            box-shadow:
                0 22px 46px rgba(15, 23, 42, .32),
                0 0 0 2px rgba(59, 130, 246, .50),
                0 0 0 6px rgba(14, 165, 233, .12),
                inset 0 0 0 1px rgba(255, 255, 255, .74),
                inset 0 0 42px rgba(59, 130, 246, .18);
        }
        .adventurer-card-hero.is-support-pass-blue-gold::before {
            inset: 8px;
            border: 1px solid rgba(59, 130, 246, .28);
            background:
                linear-gradient(90deg, rgba(255,255,255,.84) 0%, rgba(219,234,254,.60) 50%, rgba(14,165,233,.10) 100%);
            box-shadow:
                inset 0 0 0 1px rgba(255, 255, 255, .62),
                inset 0 18px 34px rgba(255, 255, 255, .32);
        }
        .adventurer-card-hero.is-support-pass-blue-gold::after {
            filter: drop-shadow(0 0 5px rgba(59, 130, 246, .45));
        }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-pass-seal {
            filter: drop-shadow(0 8px 12px rgba(15, 23, 42, .25));
        }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-avatar {
            top: 96px;
            background: linear-gradient(180deg, rgba(239,246,255,.96), rgba(219,234,254,.78));
            box-shadow:
                0 14px 26px rgba(30, 64, 175, .16),
                0 0 0 1px rgba(59, 130, 246, .32),
                inset 0 0 18px rgba(255, 255, 255, .58);
        }
            .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-title {
                padding-top: 28px;
                min-height: 150px;
            }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-title h3 {
            color: #1e3a8a;
            text-shadow:
                0 2px 0 rgba(255, 255, 255, .80),
                0 8px 18px rgba(30, 64, 175, .18);
        }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-title p {
            color: #334155;
        }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-power-pill {
            border-color: rgba(96, 165, 250, .54);
            background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(219,234,254,.86));
            color: #1e3a8a;
            box-shadow:
                0 8px 18px rgba(30, 64, 175, .12),
                inset 0 0 0 1px rgba(255, 255, 255, .72);
        }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-vital-bar {
            border-color: rgba(59, 130, 246, .32);
            background: rgba(239, 246, 255, .78);
            box-shadow:
                inset 0 1px 2px rgba(15,23,42,.14),
                0 1px 0 rgba(255,255,255,.66);
        }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-badge {
            border-color: rgba(59, 130, 246, .30);
            background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(219,234,254,.80));
            box-shadow:
                0 8px 18px rgba(30, 64, 175, .10),
                inset 0 0 0 1px rgba(255, 255, 255, .62);
        }
        .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-badge:nth-child(odd) {
            background: linear-gradient(180deg, rgba(239,246,255,.94), rgba(224,242,254,.78));
        }
        .adventurer-card-inner.is-support-pass-blue-gold-card .adventurer-card-section {
            position: relative;
            overflow: hidden;
            border-color: rgba(59, 130, 246, .34);
            background:
                linear-gradient(180deg, rgba(255,255,255,.94), rgba(239,246,255,.88)),
                repeating-linear-gradient(135deg, rgba(30,64,175,.07) 0 1px, transparent 1px 10px);
            box-shadow:
                0 12px 24px rgba(30, 64, 175, .11),
                inset 0 0 0 1px rgba(255, 255, 255, .72);
        }
        .adventurer-card-inner.is-support-pass-blue-gold-card .adventurer-card-section::before {
            content: "";
            position: absolute;
            inset: 0;
            border-top: 4px solid rgba(37, 99, 235, .52);
            border-bottom: 1px solid rgba(56, 189, 248, .22);
            pointer-events: none;
        }
        .adventurer-card-inner.is-support-pass-blue-gold-card .adventurer-card-section > div:first-child {
            position: relative;
            color: #1e3a8a !important;
            text-shadow: 0 1px 0 rgba(255, 255, 255, .78);
        }
        .adventurer-card-inner.is-support-pass-blue-gold-card .adventurer-card-section .grid > div,
        .adventurer-card-inner.is-support-pass-blue-gold-card .adventurer-card-section .flex.min-h-10 {
            border-color: rgba(59, 130, 246, .22) !important;
            background: linear-gradient(180deg, rgba(255,255,255,.94), rgba(239,246,255,.88)) !important;
            box-shadow:
                0 6px 12px rgba(30, 64, 175, .07),
                inset 0 0 0 1px rgba(255,255,255,.66) !important;
        }
        .adventurer-card-inner.is-support-pass-blue-gold-card .adventurer-card-pass-record {
            border-color: rgba(59, 130, 246, .30) !important;
            background: linear-gradient(135deg, rgba(239,246,255,.96), rgba(224,242,254,.82)) !important;
        }
        .valmon-badge-case {
            margin-top: 12px;
            border-radius: 14px;
            border: 1px solid rgba(191, 146, 55, .36);
            background: linear-gradient(180deg, rgba(255, 253, 247, .92), rgba(255, 247, 225, .84));
            box-shadow: 0 8px 18px rgba(92, 64, 17, .10);
            padding: 7px;
        }
        .valmon-badge-case-tray {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 6px;
            border-radius: 12px;
            border: 1px solid rgba(43, 37, 31, .62);
            background:
                var(--valmon-case-bg, url('{{ asset('images/profile/valmon_case01.webp') }}')) center / cover no-repeat,
                linear-gradient(135deg, #1d211d 0%, #101412 100%);
            box-shadow: inset 0 3px 12px rgba(0,0,0,.46), inset 0 0 0 1px rgba(255,255,255,.10);
            padding: 10px;
        }
        .valmon-badge-slot {
            position: relative;
            display: grid;
            aspect-ratio: 1;
            place-items: center;
            border-radius: 999px;
            border: 1px solid rgba(220, 184, 102, .64);
            background: radial-gradient(circle at 50% 36%, #fff3c8 0 27%, #c89535 62%, #6f4b13 100%);
            box-shadow: 0 2px 5px rgba(0,0,0,.24), inset 0 1px 1px rgba(255,255,255,.58), inset 0 -3px 6px rgba(82, 47, 8, .36);
            transform: scale(.93);
        }
        .valmon-badge-slot.is-empty {
            border-color: rgba(148, 163, 184, .46);
            background: radial-gradient(circle at 50% 38%, #404846 0 30%, #252c2a 72%, #111513 100%);
            box-shadow: inset 0 1px 1px rgba(255,255,255,.10), inset 0 -4px 7px rgba(0,0,0,.42), 0 2px 5px rgba(0,0,0,.20);
        }
        .valmon-badge-image {
            max-width: 80%;
            max-height: 80%;
            object-fit: contain;
            filter: drop-shadow(0 3px 4px rgba(0,0,0,.26));
        }
        .valmon-badge-question {
            color: rgba(255,255,255,.56);
            font-size: 13px;
            font-weight: 900;
            line-height: 1;
        }
        .valmon-badge-partner {
            position: absolute;
            right: -3px;
            top: -4px;
            display: grid;
            width: 15px;
            height: 15px;
            place-items: center;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,.86);
            background: #16a34a;
            color: #fff;
            font-size: 9px;
            font-weight: 900;
            line-height: 1;
            box-shadow: 0 2px 5px rgba(0,0,0,.22);
        }
        @media (max-width: 420px) {
            .adventurer-card-modal {
                width: 94vw;
                border-radius: 20px;
            }
            .adventurer-card-inner {
                padding: 20px 10px 14px;
            }
            .adventurer-card-hero {
                min-height: 360px;
            }
            .adventurer-card-hero::after {
                background-size: 126% 126%;
            }
            .adventurer-card-avatar {
                left: 32px;
                top: 98px;
                width: 88px;
                height: 88px;
            }
            .adventurer-card-avatar::after {
                inset: -19px;
            }
            .adventurer-card-title {
                min-height: 130px;
                padding: 26px 32px 0;
            }
            .adventurer-card-hero.is-support-pass .adventurer-card-title,
            .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-title {
                padding-top: 26px;
                min-height: 130px;
            }
            .adventurer-card-title h3 {
                font-size: 23px;
            }
            .adventurer-card-title p {
                font-size: 15px;
            }
            .adventurer-card-equipped-title {
                max-width: min(90%, 270px);
                font-size: 12px;
                padding: 0;
            }
            .adventurer-card-vitals {
                margin-left: 134px;
                margin-right: 28px;
                margin-top: -2px;
            }
            .adventurer-card-badges {
                gap: 5px;
                padding: 14px 44px 16px;
            }
            .adventurer-card-hero.is-support-pass .adventurer-card-badges,
            .adventurer-card-hero.is-support-pass-blue-gold .adventurer-card-badges {
                padding-top: 36px;
                padding-bottom: 10px;
            }
            .adventurer-card-badge {
                min-height: 46px;
            }
            .adventurer-card-comment {
                margin-inline: 50px;
                margin-bottom: 14px;
            }
            .adventurer-card-medals {
                padding-inline: 64px;
            }
            .valmon-badge-case-tray {
                gap: 5px;
                padding: 8px;
            }
            .valmon-badge-question {
                font-size: 12px;
            }
        }
    </style>
    @if(!empty($topPlayer))
        <div class="relative left-1/2 z-40 mb-3 -mt-4 w-full -translate-x-1/2 overflow-visible border-b border-[#d4af37]/50 bg-white shadow-[0_4px_18px_rgba(15,23,42,0.10)] sm:-mt-6">
            {{-- grid: [icon] [名前/レベル/職/戦力] [HP/SPバー] [探索力/ゴールド/輝石] [ベル] --}}
            <div class="mx-auto grid max-w-screen-2xl grid-cols-[auto_minmax(5.75rem,1.35fr)_minmax(4rem,7rem)_minmax(4.8rem,auto)_auto] grid-rows-2 items-center gap-x-1.5 px-2.5 py-1.5 sm:grid-cols-[auto_minmax(8rem,1.4fr)_minmax(5rem,8rem)_minmax(5.8rem,auto)_auto] sm:gap-x-2 sm:px-4 lg:px-6"
                 style="row-gap:2px;">

                {{-- アイコン (2行にまたがる) --}}
                <div class="row-span-2 flex h-12 w-12 shrink-0 items-center justify-center sm:h-14 sm:w-14">
                    <img src="{{ $topPlayer['icon'] }}" alt="{{ $topPlayer['name'] }}" class="h-full w-full object-contain drop-shadow-sm">
                </div>

                {{-- 名前/レベル/職/戦力 --}}
                <div class="col-start-2 row-span-2 row-start-1 min-w-0 self-center">
                    <div x-init="
                            const el = $el;
                            el.style.fontSize = '13px';
                            if (el.scrollWidth > el.offsetWidth) el.style.fontSize = '11px';
                         "
                         class="overflow-hidden whitespace-nowrap font-black leading-tight text-slate-950 sm:!text-[15px]">
                        {{ $topPlayer['name'] }}
                    </div>
                    <div class="mt-0.5 space-y-0.5 text-[10px] font-bold leading-none text-slate-400 sm:text-[11px]">
                        <div class="flex items-center gap-1 whitespace-nowrap">
                            <span>Lv {{ number_format($topPlayer['level']) }}</span>
                            @if(!empty($topPlayer['support_pass']['active']))
                                <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-1.5 py-px text-[9px] font-black leading-none text-amber-700">支援パス</span>
                            @endif
                        </div>
                        <div class="truncate">{{ $topPlayer['job'] }}★{{ $topPlayer['job_rank'] }}</div>
                        <div class="whitespace-nowrap text-slate-500">戦力 {{ number_format($topPlayer['power'] ?? 0) }}</div>
                    </div>
                </div>

                {{-- HP バー (1行目) --}}
                @php
                    $fmt = fn($v) => $v >= 10000 ? round($v/1000,1).'k' : number_format($v);
                    $hpPercent = (int) ($topPlayer['hp_percent'] ?? 0);
                    $hpBarClass = $hpPercent >= 60 ? 'bg-emerald-500' : ($hpPercent >= 30 ? 'bg-amber-400' : 'bg-rose-500');
                    $hpTextClass = $hpPercent >= 60 ? 'text-emerald-600' : ($hpPercent >= 30 ? 'text-amber-500' : 'text-rose-500');
                @endphp
                <div class="col-start-3 row-start-1 min-w-0 self-end pb-0.5">
                    <div class="mb-0.5 flex items-center justify-between gap-1">
                        <span class="text-[10px] font-black {{ $hpTextClass }} sm:text-xs">HP</span>
                        <span class="text-[9px] font-black tabular-nums text-slate-700 sm:hidden">
                            {{ $fmt($topPlayer['hp']) }}<span class="text-slate-400">/{{ $fmt($topPlayer['max_hp']) }}</span>
                        </span>
                        <span class="hidden text-[9px] font-black tabular-nums text-slate-700 sm:inline sm:text-[11px]">
                            {{ number_format($topPlayer['hp']) }}<span class="text-slate-400">/{{ number_format($topPlayer['max_hp']) }}</span>
                        </span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full {{ $hpBarClass }}" style="width: {{ $topPlayer['hp_percent'] }}%"></div>
                    </div>
                </div>

                {{-- 探索力 / ゴールド / 輝石 --}}
                <div class="col-start-4 row-span-2 row-start-1 flex min-w-0 flex-col items-end justify-center gap-0.5 text-[9px] font-black leading-none tabular-nums sm:text-[10px]">
                    @if(!empty($topPlayer['exploration_stamina']))
                        <div class="flex min-w-0 items-center justify-end gap-0.5 text-blue-900"
                             title="探索力"
                             x-data="{
                                 current: {{ (int) $topPlayer['exploration_stamina']['current'] }},
                                 max: {{ (int) $topPlayer['exploration_stamina']['max'] }},
                                 recoverySeconds: {{ (int) ($topPlayer['exploration_stamina']['recovery_seconds'] ?? 60) }},
                                 nextRecoverySeconds: {{ (int) ($topPlayer['exploration_stamina']['next_recovery_seconds'] ?? 0) }},
                                 timer: null,
                                 nextAt: null,
                                 stopTimer() {
                                     if (this.timer) {
                                         clearInterval(this.timer);
                                         this.timer = null;
                                     }
                                 },
                                 startTimer() {
                                     this.stopTimer();
                                     if (this.current >= this.max) return;
                                     if (this.nextRecoverySeconds <= 0) {
                                         this.nextRecoverySeconds = this.recoverySeconds;
                                     }
                                     this.nextAt = Date.now() + (this.nextRecoverySeconds * 1000);
                                     this.timer = setInterval(() => {
                                         if (!this.$root?.isConnected) {
                                             this.stopTimer();
                                             return;
                                         }
                                         if (this.current >= this.max) {
                                             this.stopTimer();
                                             return;
                                         }
                                         const now = Date.now();
                                         if (now >= this.nextAt) {
                                             const gained = 1 + Math.floor((now - this.nextAt) / (this.recoverySeconds * 1000));
                                             this.current = Math.min(this.max, this.current + gained);
                                             this.nextAt += gained * this.recoverySeconds * 1000;
                                         }
                                     }, 1000);
                                 },
                                 init() {
                                     this.startTimer();
                                 }
                             }"
                             @valzeria-stamina-sync.window="
                                 current = Math.max(0, Number($event.detail.current || 0));
                                 if ($event.detail.max !== null && $event.detail.max !== undefined) {
                                     max = Math.max(0, Number($event.detail.max || 0));
                                 }
                                 if ($event.detail.recoverySeconds !== null && $event.detail.recoverySeconds !== undefined) {
                                     recoverySeconds = Math.max(1, Number($event.detail.recoverySeconds || recoverySeconds));
                                 }
                                 nextRecoverySeconds = Math.max(0, Number($event.detail.nextRecoverySeconds || recoverySeconds));
                                 startTimer();
                             ">
                            <img src="{{ asset('images/icon/icon_082.webp') }}" alt="" class="h-3.5 w-3.5 shrink-0 object-contain">
                            <span class="whitespace-nowrap"><span x-text="current.toLocaleString()">{{ number_format((int) $topPlayer['exploration_stamina']['current']) }}</span><span class="text-slate-400">/{{ number_format((int) $topPlayer['exploration_stamina']['max']) }}</span></span>
                        </div>
                    @endif
                    <div class="flex min-w-0 items-center justify-end gap-0.5 text-slate-900" title="ゴールド">
                        <img src="{{ asset('images/icon/icon_083.webp') }}" alt="" class="h-3.5 w-3.5 shrink-0 object-contain">
                        <span class="whitespace-nowrap">{{ number_format($topPlayer['gold']) }}<span class="ml-0.5 text-[8px] font-bold text-amber-600 sm:text-[9px]">G</span></span>
                    </div>
                    <div class="flex min-w-0 items-center justify-end gap-0.5 text-slate-900" title="輝石">
                        <img src="{{ asset('images/icon/icon_084.webp') }}" alt="" class="h-3.5 w-3.5 shrink-0 object-contain">
                        <span class="whitespace-nowrap">{{ number_format($topPlayer['kiseki']) }}</span>
                    </div>
                </div>

                {{-- ベル (2行にまたがる) --}}
                <div class="col-start-5 row-span-2 row-start-1 flex shrink-0 items-center self-center" @click.outside="notificationOpen = false">
                    <button type="button"
                            @click="notificationOpen = !notificationOpen"
                            class="relative flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 shadow-sm transition active:scale-95 sm:h-10 sm:w-10 sm:rounded-xl"
                            aria-label="通知">
                        <svg class="h-4 w-4 sm:h-5 sm:w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-5h-1V10a6 6 0 1 0-12 0v7H5a1 1 0 0 0 0 2h14a1 1 0 1 0 0-2Z"/>
                        </svg>
                        @if($unreadNotificationCount > 0)
                            <span class="absolute -right-1 -top-1 flex min-h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-black leading-none text-white ring-2 ring-white sm:min-h-5 sm:min-w-5 sm:text-[10px]">
                                {{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}
                            </span>
                        @endif
                    </button>

                    <div x-show="notificationOpen"
                         x-cloak
                         x-transition.origin.top.right
                         class="absolute right-0 top-10 z-50 w-[min(20rem,calc(100vw-1rem))] overflow-hidden rounded-xl border border-slate-200 bg-white text-left shadow-2xl sm:top-12">
                        <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2">
                            <div class="text-sm font-black text-slate-900">通知</div>
                            @if($unreadNotificationCount > 0)
                                <button type="button"
                                        wire:click="markAllNotificationsRead"
                                        class="rounded-md px-2 py-1 text-[11px] font-bold text-amber-700 hover:bg-amber-50">
                                    すべて既読
                                </button>
                            @endif
                        </div>
                        <div class="max-h-80 overflow-y-auto">
                            @forelse($notifications as $notification)
                                @php
                                    $notificationUrl = (string) ($notification->url ?? '');
                                    $opensInNewWindow = $notificationUrl !== ''
                                        && $notification->type === 'note_rss_update';
                                @endphp
                                @if($opensInNewWindow)
                                    <a href="{{ $notificationUrl }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       wire:click="markNotificationRead({{ $notification->id }})"
                                       @click="notificationOpen = false"
                                       class="block w-full border-b border-slate-100 px-3 py-2 text-left transition last:border-b-0 hover:bg-amber-50 {{ $notification->read_at ? 'bg-white' : 'bg-amber-50/70' }}">
                                        <div class="flex items-start gap-2">
                                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-slate-200' : 'bg-rose-500' }}"></span>
                                            <div class="min-w-0">
                                                <div class="truncate text-xs font-black text-slate-900">{{ $notification->title }}</div>
                                                @if($notification->body)
                                                    <div class="mt-0.5 line-clamp-2 text-[11px] font-bold leading-snug text-slate-600">{{ $notification->body }}</div>
                                                @endif
                                                <div class="mt-1 flex items-center justify-between gap-2">
                                                    <span class="text-[10px] font-bold text-slate-400">{{ $notification->created_at?->diffForHumans() }}</span>
                                                    <span class="shrink-0 text-[10px] font-black text-amber-700">{{ $notification->action_label ?: '新しいタブで開く' }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                @else
                                    <button type="button"
                                            wire:click="openNotification({{ $notification->id }})"
                                            class="block w-full border-b border-slate-100 px-3 py-2 text-left transition last:border-b-0 hover:bg-amber-50 {{ $notification->read_at ? 'bg-white' : 'bg-amber-50/70' }}">
                                        <div class="flex items-start gap-2">
                                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-slate-200' : 'bg-rose-500' }}"></span>
                                            <div class="min-w-0">
                                                <div class="truncate text-xs font-black text-slate-900">{{ $notification->title }}</div>
                                                @if($notification->body)
                                                    <div class="mt-0.5 line-clamp-2 text-[11px] font-bold leading-snug text-slate-600">{{ $notification->body }}</div>
                                                @endif
                                                <div class="mt-1 flex items-center justify-between gap-2">
                                                    <span class="text-[10px] font-bold text-slate-400">{{ $notification->created_at?->diffForHumans() }}</span>
                                                    @if(!empty($notification->action_label))
                                                        <span class="shrink-0 text-[10px] font-black text-amber-700">{{ $notification->action_label }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </button>
                                @endif
                            @empty
                                <div class="px-3 py-5 text-center text-xs font-bold text-slate-500">通知はありません</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- SP バー (2行目) --}}
                <div class="col-start-3 row-start-2 min-w-0 self-start pt-0.5">
                    <div class="mb-0.5 flex items-center justify-between gap-1">
                        <span class="text-[10px] font-black text-sky-500 sm:text-xs">SP</span>
                        <span class="text-[9px] font-black tabular-nums text-slate-700 sm:hidden">
                            {{ $fmt($topPlayer['sp']) }}<span class="text-slate-400">/{{ $fmt($topPlayer['max_sp']) }}</span>
                        </span>
                        <span class="hidden text-[9px] font-black tabular-nums text-slate-700 sm:inline sm:text-[11px]">
                            {{ number_format($topPlayer['sp']) }}<span class="text-slate-400">/{{ number_format($topPlayer['max_sp']) }}</span>
                        </span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-sky-500" style="width: {{ $topPlayer['sp_percent'] }}%"></div>
                    </div>
                </div>

            </div>
            <div class="h-px bg-gradient-to-r from-transparent via-[#d4af37] to-transparent"></div>
        </div>
    @endif

    @if($showCityPanel)
    <!-- 1. 全幅ヘッダーエリア -->
    <div class="bg-white rounded-lg shadow-[0_5px_16px_rgba(126,96,28,0.14)] border border-[#d4af37] flex-shrink-0 overflow-hidden w-full font-sans text-xs sm:text-sm">
        <!-- 街ヘッダー -->
        <div class="px-2.5 py-2 sm:px-3 sm:py-2.5 flex flex-col md:flex-row justify-between items-center relative bg-cover bg-right"
             style="background-image: url('{{ asset('images/' . ($cityBackground ?: 'bg-castle.webp')) }}');">
            <!-- 背景を薄くするためのオーバーレイ（お城が見えるように薄く調整） -->
            <div class="absolute inset-0 bg-white/45"></div>
            <!-- 文字の後ろだけ少し白くするためのグラデーション -->
            <div class="absolute inset-0 bg-gradient-to-r from-white/95 via-white/82 to-white/25"></div>
            
            <div class="relative flex min-w-0 w-full items-center gap-2.5">
                <!-- エンブレム画像 -->
                <div class="w-11 h-11 sm:w-14 sm:h-14 flex items-center justify-center -my-1.5 drop-shadow-md shrink-0">
                    <img src="{{ asset('images/' . $cityIcon) }}" alt="エンブレム" class="w-full h-full object-contain">
                </div>
                <div class="min-w-0 flex-1">
                    <h1 class="min-w-0 whitespace-nowrap overflow-hidden text-ellipsis font-bold tracking-wide sm:tracking-widest text-[#1e293b] drop-shadow-[0_2px_2px_rgba(255,255,255,1)]"
                        style="font-size: clamp(1.05rem, 4.8vw, 1.55rem);">
                        {{ $cityName }}
                        @if(!empty($locationName))
                            <span class="text-[0.7em] text-gray-700 tracking-normal ml-1">- {{ $locationName }}</span>
                        @endif
                    </h1>
                    <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-[11px] font-semibold text-slate-600 sm:text-xs">
                        <span class="shrink-0 text-[#c0265a]">📢</span>
                        <span class="shrink-0 font-bold text-[#1e293b]">街のお知らせ</span>
                        <div class="town-news-marquee min-w-0 flex-1" aria-label="{{ implode('　｜　', $headerInfo['news']) }}">
                            <span>{{ implode('　｜　', $headerInfo['news']) }}</span>
                        </div>
                    </div>
                </div>
                <button type="button"
                        wire:click="$dispatch('changeTab', { newLocation: 'move' })"
                        @click="window.dispatchEvent(new CustomEvent('main-tab-selected', { detail: { location: 'move' } }))"
                        class="ml-auto flex w-12 shrink-0 flex-col items-center justify-center rounded-full px-1 py-0.5 text-[#1e293b] transition active:scale-95 sm:w-14"
                        aria-label="街を移動する">
                    <img src="{{ asset('images/icon/move_map.png') }}" alt="" class="h-7 w-7 object-contain drop-shadow-sm sm:h-8 sm:w-8">
                    <span class="-mt-0.5 whitespace-nowrap text-[9px] font-black leading-none tracking-normal sm:text-[10px]">移動する</span>
                </button>
            </div>

            <div class="relative mt-0.5 w-full min-w-0 text-[10px] font-bold leading-4 sm:text-[11px]">
                <div class="text-gray-700">現在の冒険者：（{{ count($onlinePlayers) }}人）</div>
                <div class="min-w-0 pr-10 sm:pr-12">
                    <div class="flex flex-wrap items-center overflow-hidden text-[#1e40af] font-medium transition-all md:max-h-none md:overflow-visible"
                         :class="playersExpanded ? 'max-h-32 overflow-y-auto' : 'max-h-8'">
                        @forelse($onlinePlayers as $player)
                            <a href="#"
                               wire:click.prevent="openPlayerModal({{ $player['id'] }})"
                               class="hover:underline hover:text-blue-800">{{ $player['name'] }}</a>
                            @if(!$loop->last)
                                <span class="text-gray-300 mx-1">|</span>
                            @endif
                        @empty
                            <span class="text-gray-500">周辺を巡回中</span>
                        @endforelse
                    </div>
                </div>
            </div>

            @if(count($onlinePlayers) > 4)
                <div class="relative mt-0.5 flex w-full justify-end md:hidden">
                    <button type="button"
                            @click="playersExpanded = !playersExpanded"
                            class="rounded border border-[#d4af37]/50 bg-white/80 px-1.5 py-0.5 text-[10px] font-black text-[#9a6b00]">
                        <span x-text="playersExpanded ? '閉じる' : '表示'"></span>
                    </button>
                </div>
            @endif
        </div>
    </div>
    @endif

    <!-- キャラ詳細モーダル -->
    <div x-show="isPlayerModalOpen" style="display: none;" x-cloak>
        <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9998; background-color: rgba(0,0,0,0.5);" wire:click="closePlayerModal"></div>
        <div class="adventurer-card-modal">
            <template x-if="playerInfo">
                <div class="adventurer-card-inner"
                     :class="{
                         'is-support-pass-card': playerInfo.adventurer_card_skin === 'support_pass',
                         'is-support-pass-blue-gold-card': playerInfo.adventurer_card_skin === 'support_pass_blue_gold'
                     }">
                    <div class="adventurer-card-hero"
                         :class="{
                             'is-support-pass': playerInfo.adventurer_card_skin === 'support_pass',
                             'is-support-pass-blue-gold': playerInfo.adventurer_card_skin === 'support_pass_blue_gold'
                         }"
                         :style="{
                             '--adventurer-card-bg': `url('${playerInfo.adventurer_card_background}')`,
                             '--adventurer-card-frame': `url('${playerInfo.adventurer_card_frame}')`,
                             '--adventurer-avatar-frame': `url('${playerInfo.adventurer_avatar_frame}')`
                         }">
                        <template x-if="['support_pass', 'support_pass_blue_gold'].includes(playerInfo.adventurer_card_skin)">
                            <div class="adventurer-card-pass-seal">
                                <img src="{{ asset('images/icon/icon_259.webp') }}" alt="支援パス">
                            </div>
                        </template>
                        <div class="adventurer-card-avatar">
                            <template x-if="playerInfo.icon">
                                <img :src="playerInfo.icon" alt="アバター">
                            </template>
                            <template x-if="!playerInfo.icon">
                                <div class="flex aspect-square items-center justify-center rounded-xl bg-white/70 text-2xl font-bold text-gray-400">?</div>
                            </template>
                        </div>
                        <div class="adventurer-card-title">
                            <div class="adventurer-card-title-line">
                                <h3 x-text="playerInfo.name"></h3>
                                <p>Lv.<span x-text="playerInfo.level"></span> / <span x-text="playerInfo.job"></span></p>
                            </div>
                            <div class="adventurer-card-power-pill">
                                <span>戦力</span>
                                <span class="text-base leading-none" x-text="Number(playerInfo.power || 0).toLocaleString()"></span>
                            </div>
                        </div>

                        <div class="adventurer-card-vitals">
                            <div class="adventurer-card-equipped-title">
                                <span x-text="playerInfo.equipped_title"></span>
                            </div>
                            <div class="adventurer-card-vital-row">
                                <span class="text-emerald-600">HP</span>
                                <div class="adventurer-card-vital-bar">
                                    <div class="adventurer-card-vital-fill bg-emerald-500" :style="`width: ${Math.max(0, Math.min(100, playerInfo.hp_percent || 0))}%`"></div>
                                </div>
                                <span class="text-slate-700"><span x-text="playerInfo.hp"></span>/<span x-text="playerInfo.max_hp"></span></span>
                            </div>
                            <div class="adventurer-card-vital-row">
                                <span class="text-blue-600">SP</span>
                                <div class="adventurer-card-vital-bar">
                                    <div class="adventurer-card-vital-fill bg-blue-500" :style="`width: ${Math.max(0, Math.min(100, playerInfo.sp_percent || 0))}%`"></div>
                                </div>
                                <span class="text-slate-700"><span x-text="playerInfo.sp"></span>/<span x-text="playerInfo.max_sp"></span></span>
                            </div>
                        </div>

                        <div class="adventurer-card-badges">
                            <div class="adventurer-card-badge flex flex-col items-center justify-center gap-0.5 px-1 py-1 text-center">
                                <img src="{{ asset('images/icon/icon_001.webp') }}" alt="" class="adventurer-card-badge-icon">
                                <div class="min-w-0">
                                    <div class="text-[10px] font-black text-[#8a5a0d]">所属</div>
                                    <div class="truncate text-xs font-black leading-tight text-slate-800" x-text="playerInfo.guild"></div>
                                </div>
                            </div>
                            <div class="adventurer-card-badge flex flex-col items-center justify-center gap-0.5 px-1 py-1 text-center">
                                <template x-if="playerInfo.arena_rank_trophy">
                                    <img :src="playerInfo.arena_rank_trophy" alt="" class="adventurer-card-badge-icon">
                                </template>
                                <template x-if="!playerInfo.arena_rank_trophy">
                                    <img src="{{ asset('images/icon/icon_002.webp') }}" alt="" class="adventurer-card-badge-icon">
                                </template>
                                <div class="min-w-0">
                                    <div class="text-[10px] font-black text-[#8a5a0d]">闘技場順位</div>
                                    <div class="truncate text-sm font-black leading-tight text-slate-800" x-text="playerInfo.arena_rank"></div>
                                </div>
                            </div>
                            <div class="adventurer-card-badge flex flex-col items-center justify-center gap-0.5 px-1 py-1 text-center">
                                <img src="{{ asset('images/icon/icon_005.webp') }}" alt="" class="adventurer-card-badge-icon">
                                <div class="min-w-0">
                                    <div class="text-[10px] font-black text-[#8a5a0d]">冒険回数</div>
                                    <div class="truncate text-sm font-black leading-tight text-slate-800">
                                        <span x-text="playerInfo.card_records.battles.value"></span><span class="text-[10px] text-orange-700" x-text="playerInfo.card_records.battles.unit"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="adventurer-card-badge flex flex-col items-center justify-center gap-0.5 px-1 py-1 text-center">
                                <img src="{{ asset('images/icon/icon_052.webp') }}" alt="" class="adventurer-card-badge-icon">
                                <div class="min-w-0">
                                    <div class="text-[10px] font-black text-[#8a5a0d]">冒険日数</div>
                                    <div class="truncate text-sm font-black leading-tight text-slate-800">
                                        <span x-text="playerInfo.card_records.days.value"></span><span class="text-[10px] text-orange-700" x-text="playerInfo.card_records.days.unit"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="adventurer-card-comment">
                            <div class="adventurer-card-comment-head">
                                <span>一言コメント</span>
                                <a x-show="playerInfo && playerInfo.is_self"
                                   href="{{ route('profile.edit') }}#profile_comment"
                                   class="adventurer-card-comment-edit">
                                    編集
                                </a>
                            </div>
                            <div class="adventurer-card-comment-text" x-text="playerInfo.profile_comment"></div>
                        </div>

                    </div>

                    <template x-if="playerInfo.favorite_weapons_enabled">
                        <div class="px-1 py-2">
                            <div class="rounded-2xl border border-[#9b7a35] bg-[linear-gradient(145deg,#1b2a45_0%,#101b31_58%,#0a1324_100%)] p-1.5 shadow-[0_2px_0_#d1a74c,0_6px_14px_rgba(15,23,42,0.28)]">
                                <div class="relative mb-1.5 flex items-center gap-2 px-1 pr-9">
                                    <div class="h-px flex-1 bg-gradient-to-r from-transparent to-amber-300/70"></div>
                                    <div class="px-1 text-[13px] font-black tracking-wide text-[#f6e6b6] drop-shadow-[0_1px_1px_rgba(0,0,0,0.85)]">お気に入り武器</div>
                                    <div class="h-px flex-1 bg-gradient-to-l from-transparent to-amber-300/70"></div>
                                    <a x-show="playerInfo.is_self" href="{{ route('profile.edit') }}#favorite_weapons" class="absolute right-1 top-1/2 -translate-y-1/2 text-[10px] font-black text-amber-100/85 underline decoration-amber-300/60 underline-offset-2 hover:text-white">編集</a>
                                </div>
                                <div class="overflow-hidden rounded-xl border border-white/70 bg-[linear-gradient(160deg,#fdfefe_0%,#edf3f8_100%)] p-1.5 shadow-[inset_0_1px_0_rgba(255,255,255,0.96),inset_0_-1px_3px_rgba(71,85,105,0.12)]">
                                    <div class="mb-1.5 flex items-center justify-between px-0.5">
                                        <span class="text-[9px] font-black tracking-[0.16em] text-slate-500">WEAPON COLLECTION</span>
                                        <span class="text-[9px] font-bold text-slate-400">お気に入り 3本</span>
                                    </div>
                                    <div class="grid grid-cols-3 gap-1.5">
                                        <template x-for="weapon in playerInfo.favorite_weapons" :key="weapon.id">
                                            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-[0_2px_6px_rgba(15,23,42,0.12)]" :style="weapon.quality ? `border-color: ${weapon.quality.border_color}` : ''">
                                                <div class="relative grid aspect-square place-items-center bg-[radial-gradient(circle_at_center,#ffffff_15%,#f7f9fc_70%,#e6edf4_100%)] p-1.5" :style="weapon.quality ? `background: ${weapon.quality.display_background}` : ''">
                                                    <img :src="weapon.image" :alt="weapon.name" class="h-full w-full object-contain drop-shadow-[0_3px_2px_rgba(41,31,14,0.32)]">
                                                    <template x-if="weapon.rank">
                                                        <span class="absolute left-1 top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-sm border border-white/40 px-1 text-[10px] font-black leading-none text-white shadow-sm" :style="`background-color: ${weapon.rank_color}`" x-text="weapon.rank"></span>
                                                    </template>
                                                    <div class="absolute bottom-1 right-1 rounded-full border px-1.5 py-0.5 font-black leading-none" :style="`color: ${weapon.enhance_style.color}; background-color: ${weapon.enhance_style.background}; border-color: ${weapon.enhance_style.border_color}; font-size: ${weapon.enhance_style.font_size}; box-shadow: ${weapon.enhance_style.shadow}`">
                                                        +<span x-text="weapon.enhance_level"></span>
                                                    </div>
                                                </div>
                                                <div class="border-t border-slate-100 bg-white px-1 py-1.5 text-center">
                                                    <div class="mb-0.5 flex h-5 items-center justify-center overflow-hidden">
                                                        <template x-if="weapon.quality">
                                                            <span class="rounded border px-1 py-px text-[9px] font-black leading-tight shadow-sm" :style="`color: ${weapon.quality.color}; background-color: ${weapon.quality.background}; border-color: ${weapon.quality.border_color}`" x-text="weapon.quality.label"></span>
                                                        </template>
                                                    </div>
                                                    <div class="break-words text-xs font-black leading-snug text-slate-800" x-text="weapon.name"></div>
                                                    <div x-show="weapon.engraving || weapon.killer" class="mt-1 flex items-center justify-center gap-1 whitespace-nowrap text-[10px] font-black leading-tight">
                                                        <template x-if="weapon.engraving">
                                                            <span :style="`color: ${weapon.engraving.color}`" x-text="weapon.engraving.label"></span>
                                                        </template>
                                                        <template x-if="weapon.engraving && weapon.killer">
                                                            <span class="text-slate-300">/</span>
                                                        </template>
                                                        <template x-if="weapon.killer">
                                                            <span :style="`color: ${weapon.killer.color}`" x-text="weapon.killer.label"></span>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-for="slot in Math.max(0, 3 - (playerInfo.favorite_weapons || []).length)" :key="`empty-favorite-${slot}`">
                                            <div class="grid aspect-[3/4] place-items-center rounded-lg border border-dashed border-slate-300 bg-white/65 px-1 text-center text-slate-400 shadow-[inset_0_1px_3px_rgba(71,85,105,0.08)]">
                                                <div>
                                                    <div class="text-xl font-normal leading-none text-slate-300">＋</div>
                                                    <div class="mt-1 text-[10px] font-black">未設定</div>
                                                    <a x-show="playerInfo.is_self" href="{{ route('profile.edit') }}#favorite_weapons" class="mt-1 inline-block text-[9px] font-black text-slate-600 underline decoration-slate-300 underline-offset-2">お気に入りに登録</a>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template x-if="playerInfo.job_master_badges_enabled">
                        <div class="px-1 py-2">
                            <div class="rounded-2xl border border-[#9b7a35] bg-[linear-gradient(145deg,#1b2a45_0%,#101b31_58%,#0a1324_100%)] p-1.5 shadow-[0_2px_0_#d1a74c,0_6px_14px_rgba(15,23,42,0.28)]">
                                <div class="mb-1.5 flex items-center gap-2 px-1">
                                    <div class="h-px flex-1 bg-gradient-to-r from-transparent to-amber-300/70"></div>
                                    <div class="px-1 text-[13px] font-black tracking-wide text-[#f6e6b6] drop-shadow-[0_1px_1px_rgba(0,0,0,0.85)]">極めた職業</div>
                                    <div class="h-px flex-1 bg-gradient-to-l from-transparent to-amber-300/70"></div>
                                </div>
                                <div class="overflow-hidden rounded-xl border border-white/70 bg-[linear-gradient(160deg,#fdfefe_0%,#edf3f8_100%)] p-1.5 shadow-[inset_0_1px_0_rgba(255,255,255,0.96),inset_0_-1px_3px_rgba(71,85,105,0.12)]">
                                    <div class="mb-1.5 flex items-center justify-between px-0.5">
                                        <span class="text-[9px] font-black tracking-[0.16em] text-slate-500">JOB COLLECTION</span>
                                        <span class="text-[9px] font-bold text-slate-400">階層を選択</span>
                                    </div>
                                    <div class="grid grid-cols-4 gap-1.5">
                                        <template x-for="tier in playerInfo.job_master_badge_tiers" :key="tier.rank">
                                            <button
                                                type="button"
                                                class="relative overflow-hidden rounded-lg border px-1.5 py-1.5 text-left transition duration-150 active:scale-[0.98]"
                                                :class="selectedJobBadgeTier === tier.rank ? 'border-transparent bg-white shadow-[0_2px_6px_rgba(15,23,42,0.16)] ring-1 ring-offset-1' : 'border-slate-200 bg-white/65 hover:border-slate-300 hover:bg-white'"
                                                :style="selectedJobBadgeTier === tier.rank ? `--tw-ring-color: ${tier.color}` : ''"
                                                @click="if (selectedJobBadgeTier === tier.rank) { selectedJobBadgeTier = null; selectedJobBadge = null; } else { selectedJobBadgeTier = tier.rank; selectedJobBadge = null; }"
                                            >
                                                <span class="absolute inset-x-0 top-0 h-0.5" :style="`background-color: ${tier.color}`"></span>
                                                <span class="block truncate text-[10px] font-black" :style="`color: ${tier.color}`" x-text="tier.label"></span>
                                                <span class="block text-[9px] font-black text-slate-500"><span x-text="tier.total"></span>職</span>
                                            </button>
                                        </template>
                                    </div>

                                    <template x-for="tier in playerInfo.job_master_badge_tiers" :key="`panel-${tier.rank}`">
                                        <div x-show="selectedJobBadgeTier === tier.rank" x-transition class="mt-2 rounded-lg border border-slate-200 bg-white p-1.5 shadow-[0_2px_7px_rgba(15,23,42,0.08)]">
                                            <div class="mb-1.5 flex items-center justify-between gap-2 rounded-md px-1.5 py-1" :style="`background-color: color-mix(in srgb, ${tier.color} 10%, white)`">
                                                <span class="text-[10px] font-black" :style="`color: ${tier.color}`" x-text="tier.label"></span>
                                                <span class="text-[9px] font-black text-slate-500"><span x-text="tier.total"></span>職を表示</span>
                                            </div>
                                            <div class="grid grid-cols-7 gap-1 rounded-md border border-slate-100 bg-[radial-gradient(circle_at_50%_0%,#f8fafc_0%,#e7eef5_100%)] p-1.5 shadow-[inset_0_1px_3px_rgba(71,85,105,0.11)]">
                                                <template x-for="job in tier.jobs" :key="job.id">
                                                    <button
                                                        type="button"
                                                        class="relative aspect-square overflow-hidden rounded-full border transition duration-150 active:scale-90"
                                                        :class="[selectedJobBadge && selectedJobBadge.id === job.id ? 'ring-2 ring-amber-400 ring-offset-1 ring-offset-slate-100' : 'hover:brightness-110', job.is_mastered ? 'border-[#fff1b8] bg-[radial-gradient(circle_at_35%_25%,#fffbe5_0%,#f4c84d_44%,#9a6814_100%)] p-[3px] shadow-[0_0_0_1px_#b77912,0_0_8px_rgba(251,191,36,0.80),0_2px_4px_rgba(15,23,42,0.32)]' : 'border-[#ab8a48] bg-[radial-gradient(circle_at_34%_27%,#ffffff_0%,#d8e1e9_25%,#5a6877_64%,#202936_100%)] p-0.5 shadow-[0_1px_0_#73542a,0_2px_3px_rgba(15,23,42,0.28)]']"
                                                        :aria-label="`${job.name}、職業ランク${job.job_level}`"
                                                        :aria-pressed="selectedJobBadge && selectedJobBadge.id === job.id"
                                                        @click="selectedJobBadge = selectedJobBadge && selectedJobBadge.id === job.id ? null : job"
                                                    >
                                                        <div class="relative grid h-full w-full place-items-center overflow-hidden rounded-full border shadow-[inset_0_0_0_1px_rgba(255,255,255,0.24),inset_0_0_6px_rgba(0,0,0,0.58)]" :class="job.is_mastered ? 'border-[#ffe5a0] bg-[radial-gradient(circle,#fff7d1_0%,#e8b63d_48%,#7d5110_100%)]' : 'border-[#f5df9d]/80 bg-[radial-gradient(circle,#1b2a42_0%,#0c1422_72%)]'">
                                                            <div x-show="!job.is_mastered" class="absolute inset-x-0 bottom-0 z-0 bg-[linear-gradient(180deg,rgba(125,211,252,0.58),rgba(14,116,144,0.78))] transition-[height] duration-500" :style="`height: ${job.fill_percent}%`"></div>
                                                            <div x-show="!job.is_mastered" class="absolute inset-x-0 bottom-[55%] z-10 h-px bg-white/60" :style="`transform: translateY(${100 - job.fill_percent}%); opacity: ${job.fill_percent ? 1 : 0}`"></div>
                                                            <template x-if="job.is_mastered && job.badge_image">
                                                                <img :src="job.badge_image" alt="" class="absolute inset-0 z-20 h-full w-full object-contain p-0.5 drop-shadow-[0_1px_1px_rgba(0,0,0,0.45)]" aria-hidden="true">
                                                            </template>
                                                            <span x-show="!job.is_mastered && job.job_level > 0" class="relative z-20 rounded-full border border-white/55 bg-slate-950/30 px-1 py-0.5 text-[9px] font-black tracking-tight text-white drop-shadow-[0_1px_1px_rgba(0,0,0,0.9)]" :style="`opacity: ${0.4 + (job.fill_percent * 0.006)}`" x-text="`★${job.job_level}`"></span>
                                                        </div>
                                                    </button>
                                                </template>
                                            </div>
                                            <div x-show="selectedJobBadge && selectedJobBadge.tier_rank === tier.rank" x-transition class="mt-2 rounded-md border border-sky-200 bg-slate-50/95 px-2 py-1.5 text-left shadow-sm">
                                                <div class="text-xs font-black text-slate-800" x-text="selectedJobBadge?.name"></div>
                                                <div x-show="selectedJobBadge?.is_mastered && selectedJobBadge?.mastered_at" class="mt-1 text-[9px] font-black text-amber-700">MASTER <span class="ml-1 text-amber-600" x-text="`マスター日 ${selectedJobBadge?.mastered_at}`"></span></div>
                                            </div>
                                        </div>
                                    </template>
                                    <div x-show="!selectedJobBadgeTier && (playerInfo.job_master_badge_tiers || []).length" class="py-3 text-center text-[10px] font-black text-slate-500">階層を選ぶと、極めた職業を確認できます。</div>
                                    <div x-show="!(playerInfo.job_master_badge_tiers || []).length" class="py-3 text-center text-[10px] font-black text-slate-500">職業マスタを読み込めませんでした。</div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- 冒険の記録 -->
                    <div class="adventurer-card-section px-4 py-3">
                        <div class="mb-2 text-lg font-black text-[#6b3f08]">冒険の記録</div>
                        <div class="grid grid-cols-2 gap-2">
                            <template x-for="record in playerInfo.adventure_records" :key="record.label">
                                <div class="flex min-h-10 items-center justify-between gap-2 rounded-lg border border-slate-100 bg-white/85 px-3 py-2 shadow-sm">
                                    <div class="min-w-0 truncate text-xs font-bold text-slate-500" x-text="record.label"></div>
                                    <div class="flex shrink-0 items-baseline gap-0.5">
                                        <span class="text-base font-black leading-none text-slate-800" x-text="record.value"></span>
                                        <span class="text-xs font-black text-orange-700" x-text="record.unit"></span>
                                    </div>
                                </div>
                            </template>
                            <div x-show="playerInfo.support_pass && playerInfo.support_pass.active"
                                 class="adventurer-card-pass-record flex min-h-10 items-center justify-between gap-2 rounded-lg border px-3 py-2 shadow-sm">
                                <div class="min-w-0 truncate text-xs font-bold text-amber-700">支援パス</div>
                                <div class="flex shrink-0 items-baseline gap-0.5">
                                    <span class="text-base font-black leading-none text-amber-900">あと<span x-text="playerInfo.support_pass.remaining_days"></span></span>
                                    <span class="text-xs font-black text-orange-700">日</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ステータス -->
                    <div class="adventurer-card-section grid grid-cols-2 gap-x-5 gap-y-2 px-4 py-3 text-base">
                        <div class="col-span-2 text-lg font-black text-[#6b3f08]">主なステータス</div>
                        <div class="flex justify-between items-center gap-2">
                            <span class="flex items-center gap-1 text-gray-500">
                                <img src="{{ asset('images/icon/icon_str.webp') }}" class="h-3.5 w-3.5 object-contain" alt="攻撃">
                                攻撃
                            </span>
                            <span class="text-[#1e293b] font-bold whitespace-nowrap">
                                <span x-text="playerInfo.stats.str.base"></span>
                                <template x-if="playerInfo.stats.str.bonus > 0"><span class="text-green-600"> +<span x-text="playerInfo.stats.str.bonus"></span></span></template>
                            </span>
                        </div>
                        <div class="flex justify-between items-center gap-2">
                            <span class="flex items-center gap-1 text-gray-500">
                                <img src="{{ asset('images/icon/icon_def.webp') }}" class="h-3.5 w-3.5 object-contain" alt="防御">
                                防御
                            </span>
                            <span class="text-[#1e293b] font-bold whitespace-nowrap">
                                <span x-text="playerInfo.stats.def.base"></span>
                                <template x-if="playerInfo.stats.def.bonus > 0"><span class="text-green-600"> +<span x-text="playerInfo.stats.def.bonus"></span></span></template>
                            </span>
                        </div>
                        <div class="flex justify-between items-center gap-2">
                            <span class="flex items-center gap-1 text-gray-500">
                                <img src="{{ asset('images/icon/icon_agi.webp') }}" class="h-3.5 w-3.5 object-contain" alt="敏捷">
                                敏捷
                            </span>
                            <span class="text-[#1e293b] font-bold whitespace-nowrap">
                                <span x-text="playerInfo.stats.agi.base"></span>
                                <template x-if="playerInfo.stats.agi.bonus > 0"><span class="text-green-600"> +<span x-text="playerInfo.stats.agi.bonus"></span></span></template>
                            </span>
                        </div>
                        <div class="flex justify-between items-center gap-2">
                            <span class="flex items-center gap-1 text-gray-500">
                                <img src="{{ asset('images/icon/icon_mag.webp') }}" class="h-3.5 w-3.5 object-contain" alt="魔力">
                                魔力
                            </span>
                            <span class="text-[#1e293b] font-bold whitespace-nowrap">
                                <span x-text="playerInfo.stats.mag.base"></span>
                                <template x-if="playerInfo.stats.mag.bonus > 0"><span class="text-green-600"> +<span x-text="playerInfo.stats.mag.bonus"></span></span></template>
                            </span>
                        </div>
                        <div class="flex justify-between items-center gap-2">
                            <span class="flex items-center gap-1 text-gray-500">
                                <img src="{{ asset('images/icon/icon_spr.webp') }}" class="h-3.5 w-3.5 object-contain" alt="精神">
                                精神
                            </span>
                            <span class="text-[#1e293b] font-bold whitespace-nowrap">
                                <span x-text="playerInfo.stats.spr.base"></span>
                                <template x-if="playerInfo.stats.spr.bonus > 0"><span class="text-green-600"> +<span x-text="playerInfo.stats.spr.bonus"></span></span></template>
                            </span>
                        </div>
                        <div class="flex justify-between items-center gap-2">
                            <span class="flex items-center gap-1 text-gray-500">
                                <img src="{{ asset('images/icon/icon_luk.webp') }}" class="h-3.5 w-3.5 object-contain" alt="運">
                                運
                            </span>
                            <span class="text-[#1e293b] font-bold whitespace-nowrap">
                                <span x-text="playerInfo.stats.luk.base"></span>
                                <template x-if="playerInfo.stats.luk.bonus > 0"><span class="text-green-600"> +<span x-text="playerInfo.stats.luk.bonus"></span></span></template>
                            </span>
                        </div>
                    </div>

                    <!-- 現在の装備 -->
                    <div class="adventurer-card-section px-4 py-3">
                        <div class="mb-2 text-lg font-black text-[#6b3f08]">現在の装備</div>
                        <div class="space-y-1.5 text-sm">
                            <div class="flex min-h-9 items-center gap-2 rounded-lg border border-slate-100 bg-white/85 px-3 py-2 shadow-sm">
                                <div class="grid h-7 w-7 shrink-0 place-items-center rounded border border-amber-200 bg-amber-50 text-xs font-black text-[#8a5a0d]">武</div>
                                <template x-if="playerInfo.equipment.weapon.rank">
                                    <span class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded px-1 text-[10px] font-black leading-none text-white shadow-sm"
                                          :style="`background-color: ${playerInfo.equipment.weapon.rank_color}`"
                                          x-text="playerInfo.equipment.weapon.rank"></span>
                                </template>
                                <span class="min-w-0 truncate font-bold text-slate-800" x-text="playerInfo.equipment.weapon.name"></span>
                                <template x-if="playerInfo.equipment.weapon.bonus_text"><span class="shrink-0 text-[10px] font-black text-violet-700" x-text="playerInfo.equipment.weapon.bonus_text"></span></template>
                            </div>
                            <div class="flex min-h-9 items-center gap-2 rounded-lg border border-slate-100 bg-white/85 px-3 py-2 shadow-sm">
                                <div class="grid h-7 w-7 shrink-0 place-items-center rounded border border-sky-200 bg-sky-50 text-xs font-black text-sky-700">防</div>
                                <template x-if="playerInfo.equipment.armor.rank">
                                    <span class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded px-1 text-[10px] font-black leading-none text-white shadow-sm"
                                          :style="`background-color: ${playerInfo.equipment.armor.rank_color}`"
                                          x-text="playerInfo.equipment.armor.rank"></span>
                                </template>
                                <span class="min-w-0 truncate font-bold text-slate-800" x-text="playerInfo.equipment.armor.name"></span>
                                <template x-if="playerInfo.equipment.armor.bonus_text"><span class="shrink-0 text-[10px] font-black text-violet-700" x-text="playerInfo.equipment.armor.bonus_text"></span></template>
                            </div>
                            <div class="flex min-h-9 items-center gap-2 rounded-lg border border-slate-100 bg-white/85 px-3 py-2 shadow-sm">
                                <div class="grid h-7 w-7 shrink-0 place-items-center rounded border border-violet-200 bg-violet-50 text-xs font-black text-violet-700">飾</div>
                                <template x-if="playerInfo.equipment.accessory.rank">
                                    <span class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded px-1 text-[10px] font-black leading-none text-white shadow-sm"
                                          :style="`background-color: ${playerInfo.equipment.accessory.rank_color}`"
                                          x-text="playerInfo.equipment.accessory.rank"></span>
                                </template>
                                <span class="min-w-0 truncate font-bold text-slate-800" x-text="playerInfo.equipment.accessory.name"></span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-center text-xs font-bold text-slate-400">
                        ※ ステータスは装備・強化・成長により変動します
                    </div>

                    <!-- ヴァルモン -->
                    <div class="valmon-badge-case"
                         :style="{ '--valmon-case-bg': `url('${playerInfo.valmon_case}')` }">
                        <div class="mb-2 flex items-center justify-between px-1">
                            <div class="text-sm font-black text-[#6b3f08]">ヴァルモン</div>
                            <div class="text-[11px] font-black text-slate-500">
                                <span x-text="(playerInfo.valmon_badges || []).filter((badge) => badge.owned).length"></span>
                                <span>/</span>
                                <span x-text="(playerInfo.valmon_badges || []).length"></span>
                            </div>
                        </div>
                        <div class="valmon-badge-case-tray">
                            <template x-for="(badge, index) in playerInfo.valmon_badges" :key="badge.species + index">
                                <div class="valmon-badge-slot"
                                     :class="badge.owned ? '' : 'is-empty'"
                                     :title="badge.owned ? `${badge.name} Lv${badge.level}` : '未発見'">
                                    <template x-if="badge.owned && badge.image">
                                        <img :src="badge.image" :alt="badge.name" class="valmon-badge-image">
                                    </template>
                                    <template x-if="!badge.owned || !badge.image">
                                        <span class="valmon-badge-question">?</span>
                                    </template>
                                    <template x-if="badge.is_partner">
                                        <span class="valmon-badge-partner">★</span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                </div>
            </template>

            <div class="px-5 pb-5 pt-1" style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                <a x-show="playerInfo && playerInfo.is_self"
                   href="{{ route('profile.edit') }}#profile_comment"
                   class="bg-[#1e40af] hover:bg-[#1e3a8a] text-white rounded font-bold shadow flex items-center gap-1"
                   style="padding: 8px 16px; font-size: 12px;">
                    <img src="{{ asset('images/icon/icon_021.webp') }}" alt="" class="h-4 w-4 object-contain"> プロフ変更
                </a>
                <button 
                    x-show="playerInfo && !playerInfo.is_self"
                    @click="$dispatch('set-chat-reply', [playerInfo.id]); isPlayerModalOpen = false; setTimeout(() => { const el = document.getElementById('chat-message-input'); if(el) { el.scrollIntoView({behavior: 'smooth', block: 'center'}); el.focus(); } }, 100);" 
                    class="bg-[#1e40af] hover:bg-[#1e3a8a] text-white rounded font-bold shadow flex items-center gap-1" 
                    style="padding: 8px 16px; font-size: 12px;">
                    <img src="{{ asset('images/icon/icon_015.webp') }}" alt="" class="h-4 w-4 object-contain"> 手紙を送る
                </button>
                <button wire:click="closePlayerModal" class="bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300 rounded" style="padding: 8px 16px; font-size: 12px; font-weight: bold; cursor: pointer;">閉じる</button>
            </div>
        </div>
    </div>
</div>
