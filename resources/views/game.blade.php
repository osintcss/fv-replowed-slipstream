<?php
// Cloudflare terminates TLS before reaching the HTTP Docker origin. Use the
// configured public URL so the Flash movie and every asset it loads share the
// HTTPS origin seen by the player.
$baseUrl = rtrim((string) config('app.url'), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FarmVille Classic</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; height: 100%; overflow: hidden; background: #3d6b1e; }
        .game-card { width: 100%; height: 100%; display: flex; flex-direction: column; background: #3d6b1e; }
        .game-header {
            background: linear-gradient(135deg, #4a7c23, #2d5016);
            padding: 0.5rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .game-header-title { color: #fbbf24; font-weight: 700; font-size: 1.125rem; font-family: 'Segoe UI', sans-serif; }
        .game-header-user { color: rgba(255,255,255,0.9); font-size: 0.8rem; font-family: 'Segoe UI', sans-serif; display: flex; align-items: center; gap: 12px; }
        .game-header-user b { color: #fbbf24; }
        .btn-neighbors {
            background: linear-gradient(180deg, #3b82f6, #2563eb);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
        }
        .btn-neighbors:hover { background: linear-gradient(180deg, #60a5fa, #3b82f6); }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .btn-market {
            background: linear-gradient(180deg, #f59e0b, #d97706);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
        }
        .btn-market:hover { background: linear-gradient(180deg, #fbbf24, #f59e0b); }
        .user-dropdown {
            position: relative;
            display: inline-block;
        }
        .btn-user {
            background: linear-gradient(180deg, #6b7280, #4b5563);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
        }
        .btn-user:hover { background: linear-gradient(180deg, #9ca3af, #6b7280); }
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: #fff;
            min-width: 140px;
            box-shadow: 0px 4px 12px rgba(0,0,0,0.15);
            border-radius: 5px;
            z-index: 10001;
            margin-top: 5px;
            overflow: hidden;
        }
        .dropdown-content a, .dropdown-content button {
            color: #333;
            padding: 10px 14px;
            text-decoration: none;
            display: block;
            font-size: 13px;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
        }
        .dropdown-content a:hover, .dropdown-content button:hover {
            background-color: #f3f4f6;
        }
        .dropdown-content .logout-btn {
            color: #dc2626;
            border-top: 1px solid #e5e7eb;
        }
        .dropdown-content.show {
            display: block;
        }
        .btn-reload {
            background: linear-gradient(180deg, #6366f1, #4f46e5);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
        }
        .btn-reload:hover { background: linear-gradient(180deg, #818cf8, #6366f1); }
        .btn-return-home {
            background: linear-gradient(180deg, #0f766e, #0f5f59);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
        }
        .btn-return-home:hover { background: linear-gradient(180deg, #14b8a6, #0f766e); }

        
        .chat-dropdown {
            position: relative;
            display: inline-block;
        }
        .btn-chat {
            background: linear-gradient(180deg, #ec4899, #db2777);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
            position: relative;
        }
        .btn-chat:hover { background: linear-gradient(180deg, #f472b6, #ec4899); }
        .chat-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #ef4444;
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            animation: pulse 2s infinite;
        }
        .chat-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background: #fff;
            width: 350px;
            max-height: 500px;
            box-shadow: 0px 4px 12px rgba(0,0,0,0.15);
            border-radius: 8px;
            z-index: 10001;
            margin-top: 5px;
            flex-direction: column;
        }
        .chat-dropdown-content.show {
            display: flex;
        }
        .chat-header {
            background: linear-gradient(180deg, #ec4899, #db2777);
            color: white;
            padding: 12px 14px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }
        .chat-close {
            background: transparent;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .chat-close:hover {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            max-height: 350px;
            background: #f9fafb;
        }
        .chat-message {
            margin-bottom: 12px;
            display: flex;
            gap: 8px;
        }
        .chat-message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            flex-shrink: 0;
            background: #e5e7eb;
            object-fit: cover;
        }
        .chat-message-content {
            flex: 1;
        }
        .chat-message-username {
            font-weight: 600;
            font-size: 12px;
            color: #374151;
            margin-bottom: 2px;
        }
        .chat-message-text {
            font-size: 13px;
            color: #4b5563;
            word-wrap: break-word;
        }
        .chat-message-time {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 2px;
        }
        .chat-input-container {
            padding: 12px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 8px;
            background: white;
            border-radius: 0 0 8px 8px;
        }
        .chat-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            font-family: 'Segoe UI', sans-serif;
        }
        .chat-input:focus {
            outline: none;
            border-color: #ec4899;
        }
        .chat-send-btn {
            background: linear-gradient(180deg, #ec4899, #db2777);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }
        .chat-send-btn:hover {
            background: linear-gradient(180deg, #f472b6, #ec4899);
        }

        
        .btn-daily-gift {
            background: linear-gradient(180deg, #10b981, #059669);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
            position: relative;
            animation: pulse-glow 2s infinite;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
        }
        .btn-daily-gift:hover {
            background: linear-gradient(180deg, #34d399, #10b981);
            animation: none;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.8);
        }
        .btn-daily-gift::before {
            content: '';
            position: absolute;
            top: -2px; left: -2px; right: -2px; bottom: -2px;
            background: linear-gradient(45deg, #fbbf24, #10b981, #fbbf24);
            border-radius: 7px;
            z-index: -1;
            animation: border-glow 3s linear infinite;
            background-size: 200% 200%;
        }
        .btn-daily-gift.claimed {
            background: linear-gradient(180deg, #6b7280, #4b5563);
            animation: none;
            box-shadow: none;
            cursor: default;
        }
        .btn-daily-gift.claimed::before { display: none; }

        
        .earn-cash-dropdown {
            position: relative;
            display: inline-block;
        }
        .btn-earn-cash {
            background: linear-gradient(180deg, #f59e0b, #d97706);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
            position: relative;
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.5);
        }
        .btn-earn-cash:hover {
            background: linear-gradient(180deg, #fbbf24, #f59e0b);
            box-shadow: 0 0 15px rgba(245, 158, 11, 0.8);
        }
        .earn-cash-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background: white;
            min-width: 320px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 5px;
            flex-direction: column;
        }
        .earn-cash-dropdown-content.show {
            display: flex;
        }
        .earn-cash-header {
            background: linear-gradient(180deg, #f59e0b, #d97706);
            color: white;
            padding: 12px 14px;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }
        .earn-cash-close {
            background: transparent;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .earn-cash-close:hover {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
        }
        .earn-cash-content {
            padding: 16px;
            background: #fffbeb;
        }
        .earn-cash-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            background: white;
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid #fde68a;
        }
        .earn-cash-item:last-child {
            margin-bottom: 0;
        }
        .earn-cash-icon {
            font-size: 24px;
            flex-shrink: 0;
        }
        .earn-cash-info h4 {
            margin: 0 0 4px 0;
            font-size: 14px;
            color: #92400e;
        }
        .earn-cash-info p {
            margin: 0;
            font-size: 12px;
            color: #78716c;
            line-height: 1.4;
        }
        .earn-cash-reward {
            background: linear-gradient(180deg, #10b981, #059669);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            margin-left: auto;
            flex-shrink: 0;
        }

        
        .btn-world-shop {
            background: linear-gradient(180deg, #8b5cf6, #7c3aed);
            color: white; border: none; padding: 5px 14px; border-radius: 5px;
            cursor: pointer; font-size: 12px; font-weight: 600;
            position: relative;
            box-shadow: 0 0 10px rgba(139, 92, 246, 0.5);
        }
        .btn-world-shop:hover {
            background: linear-gradient(180deg, #a78bfa, #8b5cf6);
            box-shadow: 0 0 15px rgba(139, 92, 246, 0.8);
        }

        
        .world-shop-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }
        .world-shop-dialog {
            background: linear-gradient(135deg, #1e3a1e, #2d5016);
            border: 3px solid #8b5cf6;
            border-radius: 15px;
            padding: 20px 30px;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 0 30px rgba(139, 92, 246, 0.5);
        }
        .world-shop-dialog h2 {
            color: #fbbf24;
            text-align: center;
            margin-bottom: 10px;
            font-family: 'Segoe UI', sans-serif;
        }
        .world-shop-dialog .shop-subtitle {
            color: #a3e635;
            text-align: center;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .world-shop-dialog .player-cash {
            color: #fbbf24;
            text-align: center;
            margin-bottom: 15px;
            font-size: 16px;
            font-weight: bold;
        }
        .world-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .world-card {
            background: linear-gradient(135deg, #374151, #1f2937);
            border: 2px solid #4b5563;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            transition: all 0.2s;
        }
        .world-card:hover {
            border-color: #8b5cf6;
            transform: translateY(-2px);
        }
        .world-card.unlocked {
            border-color: #10b981;
            opacity: 0.7;
        }
        .world-card .world-name {
            color: #fff;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 13px;
            text-transform: capitalize;
        }
        .world-card .world-status {
            color: #10b981;
            font-size: 11px;
        }
        .btn-buy-world {
            background: linear-gradient(180deg, #8b5cf6, #7c3aed);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            width: 100%;
        }
        .btn-buy-world:hover {
            background: linear-gradient(180deg, #a78bfa, #8b5cf6);
        }
        .btn-buy-world:disabled {
            background: #4b5563;
            cursor: not-allowed;
        }
        .btn-close-shop {
            background: linear-gradient(180deg, #6b7280, #4b5563);
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: block;
            margin: 0 auto;
        }
        .btn-close-shop:hover {
            background: linear-gradient(180deg, #9ca3af, #6b7280);
        }

        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 10px rgba(16, 185, 129, 0.5); }
            50% { box-shadow: 0 0 20px rgba(16, 185, 129, 0.8), 0 0 30px rgba(251, 191, 36, 0.4); }
        }
        @keyframes border-glow {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        
        .daily-gift-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }
        .daily-gift-dialog {
            background: linear-gradient(135deg, #1e3a1e, #2d5016);
            border: 3px solid #fbbf24;
            border-radius: 15px;
            padding: 30px 40px;
            text-align: center;
            box-shadow: 0 0 30px rgba(251, 191, 36, 0.5);
            animation: dialog-pop 0.3s ease-out;
        }
        @keyframes dialog-pop {
            0% { transform: scale(0.8); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .daily-gift-dialog h2 {
            color: #fbbf24;
            font-size: 24px;
            margin-bottom: 20px;
            font-family: 'Segoe UI', sans-serif;
        }
        .daily-gift-amount {
            font-size: 36px;
            font-weight: bold;
            color: #10b981;
            text-shadow: 0 0 20px rgba(16, 185, 129, 0.8);
            margin: 15px 0 5px 0;
        }
        .daily-gift-amount span {
            color: #10b981;
        }
        .daily-gift-gold {
            font-size: 36px;
            font-weight: bold;
            color: #fbbf24;
            text-shadow: 0 0 20px rgba(251, 191, 36, 0.8);
            margin: 5px 0 15px 0;
        }
        .daily-gift-gold span {
            color: #fbbf24;
        }
        .daily-gift-dialog p {
            color: white;
            font-size: 16px;
            margin-bottom: 25px;
            font-family: 'Segoe UI', sans-serif;
        }
        .btn-accept-gift {
            background: linear-gradient(180deg, #fbbf24, #f59e0b);
            color: #1e3a1e;
            border: none;
            padding: 12px 40px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-accept-gift:hover {
            background: linear-gradient(180deg, #fcd34d, #fbbf24);
            transform: scale(1.05);
        }
        .game-content { flex: 1; overflow: hidden; display: flex; flex-direction: column; background: #3d6b1e; }
        .game-content center { display: flex; flex-direction: column; flex: 1; width: 100%; height: 100%; }
        .game-content center > div { display: flex; flex-direction: column; flex: 1; width: 100%; height: 100%; }
        #innerFlashDiv { width: 100% !important; height: 100% !important; margin: 0 !important; flex: 1; }
        #flashContent { width: 100% !important; height: 100% !important; }
    </style>
</head>
<body>
    <!-- World Shop Modal -->
    <div id="worldShopModal" class="world-shop-modal">
        <div class="world-shop-dialog">
            <h2>🌍 World Shop</h2>
            <p class="shop-subtitle">Unlock new worlds for 200 Farm Cash each!</p>
            <p class="player-cash">Your Cash: <img src="/farmville/webassets/images/v854054/webassets/images/Cash_Coins/Cash_Small.png" alt="Cash" style="height: 18px; vertical-align: middle;"> <span id="shopPlayerCash">0</span></p>
            <div id="worldGrid" class="world-grid">
                <!-- Worlds will be populated by JavaScript -->
            </div>
            <button class="btn-close-shop" onclick="closeWorldShop()">Close</button>
        </div>
    </div>

    <!-- Daily Gift Modal -->
    <div id="dailyGiftModal" class="daily-gift-modal">
        <div class="daily-gift-dialog">
            <h2>Daily Gift!</h2>
            <div class="daily-gift-amount"><span id="giftCashAmount">0</span> Farm Cash</div>
            <div class="daily-gift-gold"><span id="giftGoldAmount">0</span> Coins</div>
            <p>Come back tomorrow for another gift!</p>
            <button class="btn-accept-gift" onclick="acceptDailyGift()">Accept</button>
        </div>
    </div>
    <div class="game-card">
        <div class="game-header">
            <span class="game-header-title">FarmVille Classic</span>
            <span class="game-header-user">
                Welcome <b>{{ auth()->user()->load('userMeta')->userMeta->firstName ?? auth()->user()->name }}</b>!
                <button type="button" id="dailyGiftBtn" class="btn-daily-gift" onclick="claimDailyGift()">Claim Daily Gift</button>
                <div class="earn-cash-dropdown">
                    <button type="button" class="btn-earn-cash" onclick="toggleEarnCashDropdown(event)"><img src="/farmville/webassets/images/v854054/webassets/images/Cash_Coins/Cash_Small.png" alt="Earn Cash" style="height: 16px; vertical-align: middle; margin-right: 4px;">Earn Cash</button>
                    <div id="earnCashDropdown" class="earn-cash-dropdown-content">
                        <div class="earn-cash-header">
                            <span>Ways to Earn Farm Cash</span>
                            <button class="earn-cash-close" onclick="closeEarnCashDropdown()">✕</button>
                        </div>
                        <div class="earn-cash-content">
                            <div class="earn-cash-item">
                                <span class="earn-cash-icon">🎁</span>
                                <div class="earn-cash-info">
                                    <h4>Daily Gift</h4>
                                    <p>Claim your free daily gift! Come back every day.</p>
                                </div>
                                <span class="earn-cash-reward">+1-5 Cash</span>
                            </div>
                            <div class="earn-cash-item">
                                <span class="earn-cash-icon">⬆️</span>
                                <div class="earn-cash-info">
                                    <h4>Level Up</h4>
                                    <p>Earn XP by harvesting, plowing, and caring for animals.</p>
                                </div>
                                <span class="earn-cash-reward">+3 Cash/Level</span>
                            </div>
                            <div class="earn-cash-item">
                                <span class="earn-cash-icon">🤝</span>
                                <div class="earn-cash-info">
                                    <h4>Help Neighbors</h4>
                                    <p>Visit a neighbor's farm and help them 5 times (fertilize, feed animals, etc.).</p>
                                </div>
                                <span class="earn-cash-reward">+1 Cash/Neighbor</span>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-world-shop" onclick="openWorldShop()">🌍 World Shop</button>
                <button type="button" class="btn-neighbors" onclick="openNeighborModal()">Add Neighbors</button>
                <button type="button" class="btn-market" onclick="openMarket()">Open Market</button>
                <form method="POST" action="{{ route('worlds.return-home') }}" style="margin: 0;">
                    @csrf
                    <button type="submit" class="btn-return-home" title="Return to your home farm if travel gets stuck">Return Home</button>
                </form>
                <button type="button" class="btn-reload" onclick="window.location.reload()">Reload Game</button>
                <div class="chat-dropdown">
                    <button type="button" class="btn-chat" onclick="toggleChatDropdown(event)">
                        Chat
                        <span id="chatBadge" class="chat-badge" style="display: none;">0</span>
                    </button>
                    <div id="chatDropdown" class="chat-dropdown-content">
                        <div class="chat-header">
                            <span>Global Chat</span>
                            <button class="chat-close" onclick="closeChatDropdown()">✕</button>
                        </div>
                        <div id="chatMessages" class="chat-messages"></div>
                        <div class="chat-input-container">
                            <input type="text" id="chatInput" class="chat-input" placeholder="Type a message..." maxlength="500">
                            <button class="chat-send-btn" onclick="sendChatMessage()">Send</button>
                        </div>
                    </div>
                </div>
                <div class="user-dropdown">
                    <button type="button" class="btn-user" onclick="toggleAccountDropdown(event)">Account ▾</button>
                    <div id="accountDropdown" class="dropdown-content">
                        <a href="#" onclick="openSettingsModal(); closeAccountDropdown(); return false;">Settings</a>
                        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
                            @csrf
                            <button type="submit" class="logout-btn">Logout</button>
                        </form>
                    </div>
                </div>
                <script>
                function toggleAccountDropdown(e) {
                    e.stopPropagation();
                    document.getElementById('accountDropdown').classList.toggle('show');
                }
                function closeAccountDropdown() {
                    document.getElementById('accountDropdown').classList.remove('show');
                }
                document.addEventListener('click', function(e) {
                    if (!e.target.closest('.user-dropdown')) closeAccountDropdown();
                });
                </script>
            </span>
        </div>
        <div class="game-content">
                    <script type="text/javascript" src="<?= $baseUrl ?>/farmville/v855036/webassets/js/swfobject_2_2/swfobject.js"></script>

                    <script>
                        function getExperiments() {
                            console.log('getExperiments');

                            var userExperimentData = <?= json_encode(config('experiments')) ?>;
                            return userExperimentData;
                        }

                        function getUserInfo() {
                            var pic = "{{ $user->userMeta->profile_picture ?? '' }}" || "https://fv-assets.s3.us-east-005.backblazeb2.com/profile-pictures/default_avatar.png";
                            var userInfo = {
                                "uid": "{{ $user->uid }}",
                                "name": "{{ $user->userMeta->firstName ?? '' }}",
                                "pic": pic,
                                "pic_square": pic,
                                "first_name": "{{ $user->userMeta->firstName ?? '' }}",
                                "last_name": "{{ $user->userMeta->lastName ?? '' }}",
                                "locale": "en_US",
                                "is_app_user": true
                            };
                            return userInfo;
                        }

                        function closeOnLoadPopDialogs() {
                            console.log("closeOnLoadPopDialogs")
                        }

                        function getFriendData() {
                            var friendData = @json($neighbors ?? []);
                            if (Array.isArray(friendData)) {
                                friendData = friendData.map(function(friend) {
                                    return {
                                        uid: String(friend.uid),
                                        first_name: friend.first_name || '',
                                        last_name: friend.last_name || '',
                                        name: friend.name || '',
                                        pic: friend.pic || '',
                                        pic_square: friend.pic_square || '',
                                        sex: friend.sex || 'm',
                                        is_app_user: true
                                    };
                                });
                            }
                            return friendData;
                        }

                        function getAppFriendIds() {
                            var appFriendIds = @json($neighborIds ?? []);
                            if (Array.isArray(appFriendIds)) {
                                appFriendIds = appFriendIds.map(function(id) { return String(id); });
                            }
                            return appFriendIds;
                        }

                        function addNeighborById(neighborId) {
                            fetch('/neighbors/add', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({ neighbor_id: neighborId })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    console.log('Neighbor added successfully');
                                    location.reload();
                                }
                            })
                            .catch(error => console.error('Error:', error));
                        }

                        function removeNeighborById(neighborId) {
                            fetch('/neighbors/remove', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({ neighbor_id: neighborId })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    console.log('Neighbor removed successfully');
                                    location.reload();
                                }
                            })
                            .catch(error => console.error('Error:', error));
                        }

                        function checkDailyGiftStatus() {
                            fetch('/daily-gift/status', {
                                method: 'GET',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                const btn = document.getElementById('dailyGiftBtn');
                                if (!data.canClaim) {
                                    btn.classList.add('claimed');
                                    btn.textContent = 'Gift Claimed';
                                    btn.onclick = null;
                                }
                            })
                            .catch(error => console.error('Error checking daily gift status:', error));
                        }

                        function claimDailyGift() {
                            const btn = document.getElementById('dailyGiftBtn');
                            if (btn.classList.contains('claimed')) return;

                            fetch('/daily-gift/claim', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    document.getElementById('giftCashAmount').textContent = data.cashAmount;
                                    document.getElementById('giftGoldAmount').textContent = data.goldAmount;
                                    document.getElementById('dailyGiftModal').style.display = 'flex';
                                    btn.classList.add('claimed');
                                    btn.textContent = 'Gift Claimed';
                                    btn.onclick = null;
                                } else {
                                    alert(data.message || 'Unable to claim gift');
                                }
                            })
                            .catch(error => {
                                console.error('Error claiming daily gift:', error);
                                alert('Error claiming gift. Please try again.');
                            });
                        }

                        function acceptDailyGift() {
                            document.getElementById('dailyGiftModal').style.display = 'none';
                            try {
                                var flash = FarmNS.getFlash();
                                if (flash && flash.refreshBalance) {
                                    flash.refreshBalance();
                                }
                            } catch (e) {
                                console.log('Could not refresh Flash balance:', e);
                            }
                        }

                        const WORLD_SHOP_PRICE = 200;
                        const PURCHASABLE_WORLDS = [
                            { id: 'england', name: 'England' },
                            { id: 'fisherman', name: 'Lighthouse Cove' },
                            { id: 'winterwonderland', name: 'Winter Wonderland' },
                            { id: 'australia', name: 'Australia' },
                            { id: 'space', name: 'Celestial Pastures' },
                            { id: 'candy', name: 'Candy' },
                            { id: 'fforest', name: 'Fairy Forest' },
                            { id: 'hlights', name: 'Holiday Lights' },
                            { id: 'rainforest', name: 'Rainforest' },
                            { id: 'oz', name: 'Emerald Valley' },
                            { id: 'mediterranean', name: 'Mediterranean' },
                            { id: 'oasis', name: 'Oasis' },
                            { id: 'storybook', name: 'Storybook' },
                            { id: 'sleepyhollow', name: 'Sleepy Hollow' },
                            { id: 'toyland', name: 'Toyland' },
                            { id: 'village', name: 'Village' },
                            { id: 'glen', name: 'Glen' },
                            { id: 'atlantis', name: 'Atlantis' },
                            { id: 'hallow', name: 'Hallow' }
                        ];

                        let playerUnlockedWorlds = [];
                        let playerCash = 0;

                        function openWorldShop() {
                            fetch('/api/world-shop/status', {
                                method: 'GET',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                playerUnlockedWorlds = data.unlockedWorlds || [];
                                playerCash = data.cash || 0;
                                renderWorldShop();
                                document.getElementById('worldShopModal').style.display = 'flex';
                            })
                            .catch(error => {
                                console.error('Error fetching world shop data:', error);
                                alert('Error loading World Shop. Please try again.');
                            });
                        }

                        function closeWorldShop() {
                            document.getElementById('worldShopModal').style.display = 'none';
                        }

                        function renderWorldShop() {
                            const grid = document.getElementById('worldGrid');
                            document.getElementById('shopPlayerCash').textContent = playerCash;

                            let html = '';
                            for (const world of PURCHASABLE_WORLDS) {
                                const isUnlocked = playerUnlockedWorlds.includes(world.id);
                                const canAfford = playerCash >= WORLD_SHOP_PRICE;

                                html += `
                                    <div class="world-card ${isUnlocked ? 'unlocked' : ''}">
                                        <div class="world-name">${world.name}</div>
                                        ${isUnlocked
                                            ? '<div class="world-status">✓ Unlocked</div>'
                                            : `<button class="btn-buy-world" onclick="buyWorld('${world.id}')" ${!canAfford ? 'disabled' : ''}>
                                                <img src="/farmville/webassets/images/v854054/webassets/images/Cash_Coins/Cash_Small.png" alt="Cash" style="height: 16px; vertical-align: middle;"> ${WORLD_SHOP_PRICE} Cash
                                               </button>`
                                        }
                                    </div>
                                `;
                            }
                            grid.innerHTML = html;
                        }

                        function buyWorld(worldId) {
                            if (playerCash < WORLD_SHOP_PRICE) {
                                alert('Not enough Farm Cash!');
                                return;
                            }

                            const world = PURCHASABLE_WORLDS.find(w => w.id === worldId);
                            const worldName = world ? world.name : worldId;

                            if (!confirm(`Are you sure you want to purchase "${worldName}" for ${WORLD_SHOP_PRICE} Farm Cash?`)) {
                                return;
                            }

                            fetch('/api/world-shop/purchase', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({ worldId: worldId })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert(`Successfully unlocked "${worldName}"! The page will now reload.`);
                                    window.location.reload();
                                } else {
                                    alert(data.message || 'Failed to purchase world.');
                                }
                            })
                            .catch(error => {
                                console.error('Error purchasing world:', error);
                                alert('Error purchasing world. Please try again.');
                            });
                        }

                        document.addEventListener('DOMContentLoaded', function() {
                            checkDailyGiftStatus();
                        });

                        function getNonAppFriendsInfo() {
                            console.log("getNonAppFriendsInfo")
                            tResult = {
                                "requestedFriends": {
                                    "Facebook": null
                                }
                            };
                            jsResult = {
                                "data": [{
                                    "uid": "20000",
                                    "first_name": "getNonAppFriendsInfo",
                                    "last_name": "getNonAppFriendsInfo",
                                    "name": "getNonAppFriendsInfo",
                                    "picture": {
                                        "data": {
                                            "url": ""
                                        }
                                    },
                                    "valid": true,
                                    "is_app_user": false,
                                    "allowed_restrictions": false,
                                    "pic_big": ""
                                }]
                            };
                            document.getElementById('flashapp').onNonAppFriendsCallback(jsResult);
                        }

                        function getNonAppFriendsInfoV2() {
                            console.log("getNonAppFriendsInfoV2")
                            response = [{
                                "uid": "30000",
                                "first_name": "getNonAppFriendsInfoV2",
                                "last_name": "getNonAppFriendsInfoV2",
                                "name": "getNonAppFriendsInfoV2",
                                "pic_square": "",
                                "is_app_user": true,
                                "allowed_restrictions": false,
                                "pic_big": ""
                            }];
                            document.getElementById('flashapp').onNonAppFriendsV2Callback(response);
                        }

                        function checkForPublishPermission(uid) {
                            console.log("checkForPublishPermission", uid)
                            hasPublishPermission = 1;
                            hasEmailPermission = 1;
                            document.getElementById('flashapp').onCheckForPublishPermissionComplete(hasPublishPermission, hasEmailPermission);
                        }

                        function safeConsoleLog(message) {
                            console.log("safeConsoleLog", message)
                        }

                        function onLoadStep(step) {
                            console.log("onLoadStep", step)
                            return ""
                        }

                        function onPostInit() {
                            console.log("onPostInit")
                        }

                        function onWorldLoad() {
                            console.log("onWorldLoad")
                            return false
                        }

                        function initZoom() {
                            console.log("initZoom")
                        }

                        function viewItemXmlInArtTool(param1, param2) {
                            console.log("viewItemXmlInArtTool: ", param2, param1)
                        }

                        function ztrackCount(counter, kingdom, phylum, zclass) {
                            console.log("ztrackCount: counter " + counter + " | kingdom " + kingdom + " | plylum " + phylum + " | zclass " + zclass)
                            return ""
                        }

                        function getFlashMovie(movieName) {
                            return null;
                        }

                        function getPreloaderScreenshot(swf, param1) {
                            console.log("getPreloaderScreenshot")
                        }

                        function getWorld() {
                            return null;
                        }

                        function getCurrentWorldType() {
                            var currentWorldType = "farm";
                            return currentWorldType;
                        }

                        let allPotentialNeighbors = [];
                        let currentActiveTab = 'pending';

                        function openNeighborModal() {
                            document.getElementById('neighborModal').style.display = 'block';
                            loadPendingRequests();
                            switchTab('pending');
                        }

                        function closeNeighborModal() {
                            document.getElementById('neighborModal').style.display = 'none';
                        }

                        window.onclick = function(event) {
                            const modal = document.getElementById('neighborModal');
                            if (event.target == modal) {
                                closeNeighborModal();
                            }
                        }

                        function switchTab(tabName) {
                            currentActiveTab = tabName;
                            
                            document.querySelectorAll('.tab-content').forEach(content => {
                                content.style.display = 'none';
                            });
                            
                            document.querySelectorAll('.neighbor-tab').forEach(tab => {
                                tab.style.backgroundColor = '#B8D4E3';
                                tab.style.color = '#333';
                            });
                            
                            document.getElementById(tabName + 'Content').style.display = 'block';
                            document.getElementById(tabName + 'Tab').style.backgroundColor = '#7FB3D5';
                            document.getElementById(tabName + 'Tab').style.color = 'white';
                            
                            if (tabName === 'pending') {
                                loadPendingRequests();
                            } else if (tabName === 'current') {
                                loadCurrentNeighbors();
                            } else if (tabName === 'find') {
                                loadPotentialNeighbors();
                            }
                        }

                        let pendingNeighborIds = [];

                        function loadPendingRequests() {
                            fetch('/neighbors/pending')
                                .then(response => response.json())
                                .then(data => {
                                    const pendingList = document.getElementById('pendingList');
                                    const pendingCount = document.getElementById('pendingCount');

                                    pendingCount.textContent = data.count;

                                    pendingNeighborIds = data.pending.map(n => n.uid);

                                    if (data.pending.length === 0) {
                                        pendingList.innerHTML = '<p style="text-align: center; color: #7F8C8D; padding: 20px; font-style: italic;">📭 No pending requests</p>';
                                    } else {
                                        const acceptAllBtn = `
                                            <div style="margin-bottom: 15px; text-align: right;">
                                                <button onclick="acceptAllNeighbors()" style="background: linear-gradient(180deg, #10b981, #059669); color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 600;">
                                                    ✓ Accept All (${data.pending.length})
                                                </button>
                                            </div>
                                        `;

                                        const neighborsList = data.pending.map(neighbor => {
                                            const initial = neighbor.first_name.charAt(0).toUpperCase();
                                            return `
                                                <div class="neighbor-item">
                                                    <div class="neighbor-info">
                                                        <div class="neighbor-avatar">${initial}</div>
                                                        <div>
                                                            <div class="neighbor-name">${neighbor.first_name} ${neighbor.last_name}</div>
                                                            <div class="neighbor-id">ID: ${neighbor.uid}</div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <button class="btn-action btn-accept" onclick="acceptNeighbor('${neighbor.uid}')">✓ Accept</button>
                                                        <button class="btn-action btn-reject" onclick="rejectNeighbor('${neighbor.uid}')">✗ Reject</button>
                                                    </div>
                                                </div>
                                            `;
                                        }).join('');

                                        pendingList.innerHTML = acceptAllBtn + neighborsList;
                                    }
                                })
                                .catch(error => {
                                    console.error('Error loading neighbor requests:', error);
                                    document.getElementById('pendingList').innerHTML = '<p style="text-align: center; color: #E74C3C;">❌ Error loading neighbor requests</p>';
                                });
                        }

                        async function acceptAllNeighbors() {
                            if (pendingNeighborIds.length === 0) return;

                            if (!confirm(`Accept all ${pendingNeighborIds.length} neighbor requests?`)) return;

                            const pendingList = document.getElementById('pendingList');
                            pendingList.innerHTML = '<p style="text-align: center; color: #3498DB; padding: 20px;">⏳ Accepting all neighbors...</p>';

                            let successCount = 0;
                            let failCount = 0;

                            for (const neighborId of pendingNeighborIds) {
                                try {
                                    const response = await fetch('/neighbors/accept', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                        },
                                        body: JSON.stringify({ neighbor_id: neighborId })
                                    });
                                    const data = await response.json();
                                    if (data.success) {
                                        successCount++;
                                    } else {
                                        failCount++;
                                    }
                                } catch (error) {
                                    console.error('Error accepting neighbor:', neighborId, error);
                                    failCount++;
                                }
                            }

                            loadPendingRequests();
                            loadCurrentNeighbors();
                            updateNotificationBadge();

                            let message = `✅ Accepted ${successCount} neighbor(s)`;
                            if (failCount > 0) {
                                message += `\n❌ Failed: ${failCount}`;
                            }
                            message += '\n\nReload the game now to see your new neighbors in the neighbor bar?';

                            if (confirm(message)) {
                                location.reload();
                            }
                        }

                        function loadCurrentNeighbors() {
                            fetch('/neighbors/data')
                                .then(response => response.json())
                                .then(data => {
                                    const currentList = document.getElementById('currentList');
                                    const currentCount = document.getElementById('currentCount');
                                    const neighbors = data.neighbors || [];
                                    
                                    currentCount.textContent = neighbors.length;
                                    
                                    if (neighbors.length === 0) {
                                        currentList.innerHTML = '<p style="text-align: center; color: #7F8C8D; padding: 20px; font-style: italic;">👥 You don\'t have neighbors yet</p>';
                                    } else {
                                        currentList.innerHTML = neighbors.map(neighbor => {
                                            const initial = neighbor.first_name.charAt(0).toUpperCase();
                                            return `
                                                <div class="neighbor-item">
                                                    <div class="neighbor-info">
                                                        <div class="neighbor-avatar">${initial}</div>
                                                        <div>
                                                            <div class="neighbor-name">${neighbor.first_name} ${neighbor.last_name}</div>
                                                            <div class="neighbor-id">ID: ${neighbor.uid}</div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <button class="btn-action btn-remove" onclick="removeNeighbor('${neighbor.uid}')">🗑️ Remove</button>
                                                    </div>
                                                </div>
                                            `;
                                        }).join('');
                                    }
                                })
                                .catch(error => {
                                    console.error('Error loading neighbors:', error);
                                    document.getElementById('currentList').innerHTML = '<p style="text-align: center; color: #E74C3C;">❌ Error loading neighbors</p>';
                                });
                        }

                        function loadPotentialNeighbors() {
                            fetch('/neighbors/potential')
                                .then(response => response.json())
                                .then(data => {
                                    allPotentialNeighbors = data.users || [];
                                    displayPotentialNeighbors(allPotentialNeighbors);
                                })
                                .catch(error => {
                                    console.error('Error loading users:', error);
                                    document.getElementById('findList').innerHTML = '<p style="text-align: center; color: #E74C3C;">❌ Error loading users</p>';
                                });
                        }

                        function displayPotentialNeighbors(users) {
                            const findList = document.getElementById('findList');
                            
                            if (users.length === 0) {
                                findList.innerHTML = '<p style="text-align: center; color: #7F8C8D; padding: 20px; font-style: italic;">🔍 No users found</p>';
                            } else {
                                findList.innerHTML = users.map(user => {
                                    const initial = user.first_name.charAt(0).toUpperCase();
                                    return `
                                        <div class="neighbor-item">
                                            <div class="neighbor-info">
                                                <div class="neighbor-avatar">${initial}</div>
                                                <div>
                                                    <div class="neighbor-name">${user.first_name} ${user.last_name}</div>
                                                    <div class="neighbor-id">ID: ${user.uid}</div>
                                                </div>
                                            </div>
                                            <div>
                                                <button class="btn-action btn-add" onclick="sendNeighborRequest('${user.uid}')">➕ Add</button>
                                            </div>
                                        </div>
                                    `;
                                }).join('');
                            }
                        }

                        function filterPotentialNeighbors() {
                            const searchTerm = document.getElementById('searchNeighbor').value.toLowerCase();
                            const filtered = allPotentialNeighbors.filter(user => {
                                return user.first_name.toLowerCase().includes(searchTerm) ||
                                    user.last_name.toLowerCase().includes(searchTerm) ||
                                    user.name.toLowerCase().includes(searchTerm) ||
                                    user.uid.toLowerCase().includes(searchTerm);
                            });
                            displayPotentialNeighbors(filtered);
                        }

                        function acceptNeighbor(neighborId) {
                            if (!confirm('Do you want to accept this neighbor request?')) return;

                            fetch('/neighbors/accept', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({ neighbor_id: neighborId })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    loadPendingRequests();
                                    loadCurrentNeighbors();
                                    updateNotificationBadge();

                                    const shouldReload = confirm('✅ ' + data.message + '\n\nReload the game now to see your new neighbor in the neighbor bar?');
                                    if (shouldReload) {
                                        location.reload();
                                    }
                                } else {
                                    alert('❌ Error accepting neighbor');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('❌ Error processing neighbor request');
                            });
                        }

                        function rejectNeighbor(neighborId) {
                            if (!confirm('Do you want to reject this neighbor request?')) return;
                            
                            fetch('/neighbors/reject', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({ neighbor_id: neighborId })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert('✅ ' + data.message);
                                    loadPendingRequests();
                                } else {
                                    alert('❌ Error rejecting neighbor request');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('❌ Error processing neighbor request');
                            });
                        }

                        function removeNeighbor(neighborId) {
                            if (!confirm('Do you want to remove this neighbor?')) return;
                            
                            fetch('/neighbors/remove', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({ neighbor_id: neighborId })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert('✅ ' + data.message);
                                    loadCurrentNeighbors();
                                    setTimeout(() => location.reload(), 1500);
                                } else {
                                    alert('❌ Error removing neighbor');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('❌ Error processing neighbor request');
                            });
                        }

                        function sendNeighborRequest(neighborId) {
                            if (!confirm('Do you want to send this neighbor request?')) return;
                            
                            fetch('/neighbors/send-request', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({ neighbor_id: neighborId })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert('✅ ' + data.message);
                                } else {
                                    alert('❌ ' + (data.error || 'Error sending neighbor request'));
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('❌ Error sending neighbor request');
                            });
                        }

                        function updateNotificationBadge() {
                            fetch('/neighbors/pending')
                                .then(response => response.json())
                                .then(data => {
                                    const addNeighborBtn = document.querySelector('.btn-neighbors');
                                    if (!addNeighborBtn) return;

                                    let badge = document.getElementById('notificationBadge');

                                    if (data.count > 0) {
                                        if (!badge) {
                                            addNeighborBtn.style.position = 'relative';
                                            badge = document.createElement('span');
                                            badge.id = 'notificationBadge';
                                            badge.style.cssText = 'position: absolute; top: -8px; right: -8px; background-color: #E74C3C; color: white; border-radius: 50%; min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.3); animation: pulse 2s infinite;';
                                            addNeighborBtn.appendChild(badge);
                                        }
                                        badge.textContent = data.count;
                                        badge.style.display = 'flex';
                                    } else if (badge) {
                                        badge.style.display = 'none';
                                    }
                                })
                                .catch(error => console.error('Error updating badge:', error));
                        }

                        document.addEventListener('DOMContentLoaded', updateNotificationBadge);

                        setInterval(updateNotificationBadge, 30000);
                    </script>

                    <script>

                        if (typeof(FarmNS) === "undefined") {
                            FarmNS = {}
                        }

                        FarmNS.getFlash = function() {
                            return document.getElementById("flashapp")
                        };

                        FarmNS.setZid = function(zid) {
                            console.log("FarmNS.setZid:", zid)
                        };

                        FarmNS.initW2e = function() {
                            console.log("FarmNS.initW2e")
                        };

                        FarmNS.showW2eIron = function() {
                            console.log("FarmNS.showW2eIron")
                        };

                        FarmNS.FlashExtendedPermissionsManager = {
                            getPermissions: function() {
                                console.log("FarmNS.FlashExtendedPermissionsManager.getPermissions")
                                return [];
                            },
                            refreshExtendedPermsFlash: function(callId) {
                                console.log("FarmNS.FlashExtendedPermissionsManager.refreshExtendedPermsFlash")
                                FarmNS.getFlash().doRegisteredCallback(callId, [{
                                    publish_actions: true,
                                    user_games_activity: true,
                                    friends_games_activity: true,
                                    publish_actions: true,
                                    user_birthday: true,
                                    read_stream: true,
                                    user_friends: true,
                                    extended_permissions_gift_given: true
                                }])
                            },
                            requestExtendedPermsFlash: function(callId, perms_list, e) {
                                console.log("FarmNS.FlashExtendedPermissionsManager.requestExtendedPermsFlash:", perms_list, e)
                                FarmNS.getFlash().doRegisteredCallback(callId, [{}])
                            },
                            requestExtendedPerms: function(callId, f, e) {
                                console.log("FarmNS.FlashExtendedPermissionsManager.requestExtendedPerms:", callId, f, e)
                            },
                            checkFriendsPermissionFlash: function() {
                                console.log("FarmNS.FlashExtendedPermissionsManager.checkFriendsPermissionFlash")
                                FarmNS.getFlash().onFriendsDataLoaded(true)
                            }
                        }

                        FarmNS.Request2Manager = {
                            shareFarmstamaticPhoto: function(imagePath, message) {
                                console.log("FarmNS.Request2Manager.shareFarmstamaticPhoto:", imagePath, message)
                            },
                            sendRequestsFromFlash: function(requestData, requestMessage, requestTitle, uids, statsSource) {
                                console.log("FarmNS.Request2Manager.sendRequestsFromFlash: ", requestData, requestMessage, requestTitle, uids, statsSource)
                                let res = {
                                    'request_ids': uids
                                }
                                FarmNS.getFlash().fbresponseHandler(res)
                            }
                        }

                        FarmNS.UISandboxManager = {
                            setSendAssetNameCallbackID: function(callId) {
                                console.log("FarmNS.UISandboxManager.setSendAssetNameCallbackID")
                            },
                            setRemoveAssetNameCallbackID: function(callId2) {
                                console.log("FarmNS.UISandboxManager.setRemoveAssetNameCallbackID")
                            },
                            setAssetToSynced: function(assetName) {
                                console.log("FarmNS.UISandboxManager.setAssetToSynced")
                            },
                            onFlashLoadComplete: function() {
                                console.log("FarmNS.UISandboxManager.onFlashLoadComplete")
                            }
                        }
                    </script>

                    <script>

                        if (typeof(FB) === "undefined") {
                            FB = {}
                        }

                        FB.Facebook = {}
                        FB.Facebook.apiClient = {
                            callMethod: function(method, params, callback) {
                                console.log("FB.Facebook.apiClient.callMethod")
                                console.log(" - method:", method)
                                console.log(" - params:", params)
                                console.log(" - callback:", callback)

                                if (method === "friends.getAppUsers") {
                                    console.log(params["auth_token"])
                                    result = ["10000"]
                                    callback([], null)
                                } else if (method === "friends.get") {
                                    var result = [

                                    ];
                                    callback([], null)
                                } else if (method === "users.getLoggedInUser") {
                                    var picture = "{{ $user->userMeta->profile_picture ?? '' }}" || "https://fv-assets.s3.us-east-005.backblazeb2.com/profile-pictures/default_avatar.png";
                                    var result = {
                                        uid: "{{ $user->uid }}",
                                        firstName: "{{ $user->userMeta->firstName ?? '' }}",
                                        name: "{{ $user->userMeta->lastName ?? '' }}",
                                        picture: picture,
                                    }
                                    res = callback(result, null)
                                    console.log(" - result", res)
                                    return result
                                } else if (method === "users.getInfo") {
                                    var result = [

                                    ];
                                    callback(result, null)
                                } else if (method === "users.hasAppPermission") {
                                    callback(true, null)
                                }
                            }
                        }
                    </script>
                    <script>
                        var flashVars = {
                            "token": "2f0daceecd5afb8e59c89777513e844e92",
                            "master_id": "{{ auth()->user()->uid }}",
                            "serverTime": <?= time() ?>,
                            "app_url": "<?= $baseUrl ?>/farmville/",
                            "sn_app_url": "<?= $baseUrl ?>/farmville/",
                            "asset_url": "<?= $baseUrl ?>/farmville/assets/hashed/",
                            "isCIP": false,
                            "CHROME_FLASH_FIX_1131_CLONE": true,
                            "TRANSACTION_LATENCY_POPULATION": 1,
                            "TRANSACTION_LATENCY_MAX_ID": 100,
                            "TIMED_ACTION_MILLISECONDS_OPS": 5,
                            "AMF_DROPPED_CONNECTION_MAX_RETRIES": 10,
                            "flashRevision": "855037.855026",
                            "phpRevision": "855038",
                            "configRevision": "",
                            "xml_url": "<?= $baseUrl ?>/farmville/xml/gz/v855038-locale-v3/",
                            "master_assethash_url": "<?= $baseUrl ?>/farmville/assethash/v9/",
                            "masterysigns_amf_url": "<?= $baseUrl ?>/farmville/masterysigns/v1/",
                            "ITEMS_AMF_BUILD_TIME_REDUCTION": false,
                            "swfLocation": "<?= $baseUrl ?>/farmville/embeds/Flash/v855037.855026/FarmGame-10.swf?restore_original=1",
                            "parts_count": 3,
                            "NO_FUEL_DAY_START_TIME": "1606723200",
                            "NO_FUEL_DAY_END_TIME": "1607328000",
                            "NO_FUEL_DAY_WORLDS": "yuletide",
                            "OPS_JS_GET_FRIENDS_PERMISSION": false,
                            "game_config_url": "<?= $baseUrl ?>/farmville/xml/gz/v855038/gameSettings.xml.gz",
                            "gameSettingsCMS_url": "<?= $baseUrl ?>/farmville/xml/gz/v855038/gameSettingsCMS.xml.gz",
                            "items_url": "<?= $baseUrl ?>/farmville/xml/gz/v855038-expansions-v1/items.xml.gz",
                            "IS_MASTERY_CLEANED": true,
                            "fgsm_amf_url": "<?= $baseUrl ?>/farmville/xml/gz/v855038/fgsm.amf.gz",
                            "FGSM_AMF_ENABLED": false,
                            "OPS_FGSM_QUEST_ITEM_CAT_ENABLED": true,
                            "OPS_SOCIAL_PLUMBING_CLEANUP_TMPRT": 0,
                            "OPS_SOCIAL_PLUMBING_CLEANUP_LOGGING_TMPRT": true,
                            "OPS_TEMPID_ON_PLOTS_TMPRT": true,
                            "R2_NEIGHBOR_AUTOPOP_ENABLE": false,
                            "dialogs_url": "<?= $baseUrl ?>/farmville/xml/gz/v855038/dialogs.xml.gz",
                            // The quest-settings archive is patched during the Docker build so
                            // server-tracked actions use the AMF QuestComponent snapshot. Keep a
                            // revision in the URL: Flash otherwise reuses a previously cached,
                            // unpatched archive even after the server image has been rebuilt.
                            "quest_url": "<?= $baseUrl ?>/farmville/xml/gz/v855038/questSettings.xml.gz?revision=server-progress-4",
                            "quest_min_url": "<?= $baseUrl ?>/farmville/xml/gz/v855038/questSettings_0.xml.gz?revision=server-progress-4",
                            "OPS_TRACK_MEMORY_TRENDING": true,
                            "OPS_MEMORY_TRACKING_TIMEINTERVAL_MINUTES": 2,
                            "OPS_FLASH_CRASH_TRACKING_SECONDS": 4000,
                            "FEATURE_IFRAME": 1,
                            "FARM_SLOTS_MIN_SPIN_DELAY_MS": 1000,
                            "MEMORY_CLEANUP_LOCAL_DATA_GC": true,
                            "fotd": "{{ $fotdImages ?: ($baseUrl . '/farmville/assets/hashed/assets/fotd/Current/5169f96f29c9856ac53111433cdfff63.jpg') }}",
                            "fotdChangeTime": 3,
                            "locale": "en_US",
                            "fblocale": "en_US",
                            "regiftFeedDailyCount": 0,
                            "FEATURE_MERGEDITEMFLAG_OPT_IN_ENABLED": true,
                            "dbg_tool_mode": 0,
                            "ui_sandbox_mode": 1,
                            "force_enable_toggle_admin": 0,
                            "artupload_tool_mode": 0,
                            "quest_tool2_mode": 0,
                            "CiproToProd": "false",
                            "fb_sig_session_key": "",
                            "fb_sig_expires": "0",
                            "fb_sig_user": "{{ auth()->user()->uid }}",
                            "oauth_session": true,
                            "fb_sig_api_key": "80c6ec6628efd9a465dd223190a65bbc",
                            "fb_sig_app_id": "102452128776",
                            "fb_sig_base_domain": "farmville.com",
                            "fb_sig_ss": "102452128776",
                            "fb_sig_time": <?= time() ?>,
                            "isAdult": "0",
                            "sequence_id": 1606900539,
                            "waterEnabled": 1,
                            "showAd": 0,
                            "showInterstitialAd": 0,
                            "canFertilize": 1,
                            "giftIcon": 1,
                            "flashGift": "blackrose_singlebloom",
                            "giftIdleMission": 1,
                            "giftMission": 1,
                            "authBypass": 1,
                            "iframeRedirect": 1,
                            "soundOptimizedCheck": "false",
                            "rasterFrameJump": 3,
                            "animationMemoryLimit": 150,
                            "zaspSampleRate": 1,
                            "bridgeAgent": "false",
                            "zaspSession": "false",
                            "zaspFPS": "false",
                            "zaspWait": "false",
                            "zaspWF": "false",
                            "zaspGPI": "false",
                            "fv_dev_terrain_mapping_creation_tool": 0,
                            "disallowWither": 0,
                            "disallowPetRunaway": 0,
                            "featureExtraMastery": 0,
                            "featureExtraMasteryAnimal": 0,
                            "featureExtraMasteryTree": 0,
                            "featureExtraMasteryBloom": 0,
                            "featureExtraMasteryCropMultiplier": 2,
                            "featureExtraMasteryAnimalMultiplier": 2,
                            "featureExtraMasteryTreeMultiplier": 2,
                            "featureExtraMasteryBloomMultiplier": 1,
                            "FEATURE_ENABLE_SWEET_SEEDS_FOR_HAITI": 1,
                            "FEATURE_FLASH_CAN_ASK_EMAIL": 0,
                            "FEATURE_FLASHPARAM_GIFTBOX_EXPANSION": 1,
                            "GIFTBOX_TOTAL_ITEM_LIMIT": 10000,
                            "FEATURE_SILO_CAPACITY_LEVEL_CAP": 36,
                            "FEATURE_ORCHARDS_LEVEL_CAP": 3,
                            "FEATURE_ANIMAL_PEN_LEVEL_CAP": 6,
                            "FEATURE_ANIMAL_PEN_EXPANSIONS": 1,
                            "FEATURE_MARKET_STALL_CAPACITY_LEVEL_CAP": 139,
                            "FEATURE_FLASHPARAM_REPORT_ERRORS": 1,
                            "FEATURE_FLASHPARAM_REPORT_SWF_EXPORT_ERRORS": 0,
                            "batch_limit_runtime": 1,
                            "FEATURE_TRAVEL_ANIMATION_ASSET": "assets/dialogs/yuletide/7d2f72f99b34e0390c342e587d16c69b.swf",
                            "FLASHVAR_CRASHBUSTERS_LOGSIZE": 20,
                            "FLASHVAR_TRANSACTION_MAX_WAIT": 5000,
                            "IsInDomainShardingV2": 1,
                            "FLASHVAR_T6_LOAD_STATS_SAMPLE": 1000,
                            "FLASHVAR_LAB_SAMPLE": 100,
                            "FLASHVAR_FARM_66598_SAMPLE": 10000,
                            "FLASHVAR_FARM_65294_SAMPLE": 10000,
                            "FLASHVAR_ANIMAL_FEED_THROTTLE": 82800,
                            "QUEST_FEED_STATS_SAMPLE": 100,
                            "PHP_FEED_STATS_SAMPLE": 100,
                            "FEEDS_AS_LINK_STATS_SAMPLE": 1,
                            "FEEDS_V2_STATS_SAMPLE": 1,
                            "STREAMPUBLISH_USE_PHP_SDK": true,
                            "SHOW_FEED_POSTING_CONFIRMATION_DIALOG": true,
                            "FEATURE_FLASHPARAM_HUD_ICON_BLACKLIST": "xmoStarter,GypsyTrader,flower_shack,dragon_pen_finished,bobsberry,mystree_v2,community_matchmaking_v3,bingoXPromo,mystree,BushelBabiesV1Building,HolidayGivingTree,HlightsStarter,autumnorchard2013,ItemMembership,hangingGardens,hangingGardensFTUE,mayflowergarden2013,scratchCard,lottery,GlenStarter,JadeFallsStarter,fcSlotMachine,leSlotMachine,stpatricksbuildable2013Building,gardenamphitheater2013Building,ferriswheel2012Building,pearup,puzzleFeature,multiPanelFeatureSelector,gnomevinyard2013Building,windmill2012Building,bigbarnyard2012Building,bumpercar2012Building,carnivalBooth,GildasList,completionPack,CandyStarter,lemonaidStand,lemonaidStandv2,dreamdeer,roadtrip2013,FforestStarter,irrigation_placeSprinkler,irrigation_placeWell_16hr,irrigation_placeWell_8hr,xtiStarter,MatchmakingBeta,xdwStarter,stencil",
                            "FLASHGIFTQUEUEDICON_CONDITIONALLYADDFLASHGIFTICON_OVERRIDE": false,
                            "MAX_TRANSACTION_DEPTH": 50,
                            "FEATURE_FLASHPARAM_MYSTERY_CRATE_APPLY_LOAD_CHECK": true,
                            "FEATURE_FLASHPARAM_PEN_CONTENTS_SAMPLE": 0,
                            "PERF_MAX_BATCH_FRIENDSETS": 20,
                            "TIMED_ACTION_MAX_RETRIES": 1000,
                            "SAMPLE_OVERRIDE_FUEL": 10000,
                            "SAMPLE_OVERRIDE_FARM_WORLD_ACTION": 1000,
                            "SAMPLE_OVERRIDE_ERRORS": 100,
                            "batchLimitFunctionExceptions": "%7B%0A%20%20%20%20%22UserService.saveFeatureOptions%22%3A%201%2C%0A%20%20%20%20%22UserService.publishUserAction%22%3A%201%2C%0A%20%20%20%20%22LeaderboardService.getPassedFriendFeed%22%3A%201%2C%0A%20%20%20%20%22WorldService.performAction%22%3A%201%2C%0A%20%20%20%20%22FarmService.saveIcons%22%3A%201%2C%0A%20%20%20%20%22AvatarService.saveAvatar%22%3A%201%0A%7D",
                            "batchLimiterVerboseData": 1,
                            "req_FlashControllerStartTimestamp": <?= time() ?>,
                            "debugMode": true,
                            "neighbors": "{{ $neighborsBase64 ?? '' }}"
                        };

                        var swfCallback = function(e) {

                        }
                        var params = {
                            allowScriptAccess: "always",
                            wmode: "default",
                            allowFullScreen: "true"
                        };
                        var attrs = {
                            id: "flashapp",
                            name: "flashapp"
                        };
                        swfobject.embedSWF("<?= $baseUrl ?>/farmville/embeds/Flash/v855037.855026/FV_Preloader.swf?restore_original=1", "flashContent",
                            "100%", "100%", "10.0.0", "playerProductInstall.swf",
                            flashVars, params, attrs, swfCallback);

                        document.addEventListener('keydown', function(e) {
                            if (e.shiftKey && e.key === 'M') {
                                openMarket();
                            }
                        });

                        function openMarket() {
                            try {
                                var flash = document.getElementById("flashapp");
                                if (flash && typeof flash.onFeaturePromo === 'function') {
                                    flash.onFeaturePromo("SweetYams");
                                }
                            } catch(err) { console.log('[FV] Market open failed:', err); }
                        }
                    </script>

                    <center>
                        <!-- Neighbor Management Modal -->
                        <div id="neighborModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6);">
                            <div style="background-color: #fefefe; margin: 5% auto; padding: 0; border: 3px solid #8B4513; width: 600px; border-radius: 10px; box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19); font-family: Arial, sans-serif;">
                                <!-- Header -->
                                <div style="padding: 15px 20px; background: linear-gradient(to bottom, #7FB3D5 0%, #5C9FCC 100%); color: white; border-radius: 7px 7px 0 0; display: flex; justify-content: space-between; align-items: center;">
                                    <h2 style="margin: 0; font-size: 20px; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">🌾 Manage Neighbors</h2>
                                    <span onclick="closeNeighborModal()" style="cursor: pointer; font-size: 28px; font-weight: bold; color: white; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">&times;</span>
                                </div>
                                
                                <!-- Tabs -->
                                <div style="display: flex; background-color: #E8F4F8; border-bottom: 2px solid #5C9FCC;">
                                    <button class="neighbor-tab" onclick="switchTab('pending')" id="pendingTab" style="flex: 1; padding: 12px; background-color: #7FB3D5; color: white; border: none; cursor: pointer; font-size: 14px; font-weight: bold; transition: background-color 0.3s;">
                                        Requests <span id="pendingCount" style="background-color: #E74C3C; border-radius: 50%; padding: 2px 8px; font-size: 12px; margin-left: 5px;">0</span>
                                    </button>
                                    <button class="neighbor-tab" onclick="switchTab('current')" id="currentTab" style="flex: 1; padding: 12px; background-color: #B8D4E3; color: #333; border: none; cursor: pointer; font-size: 14px; font-weight: bold; transition: background-color 0.3s;">
                                        My Neighbors <span id="currentCount" style="background-color: #3498DB; color: white; border-radius: 50%; padding: 2px 8px; font-size: 12px; margin-left: 5px;">0</span>
                                    </button>
                                    <button class="neighbor-tab" onclick="switchTab('find')" id="findTab" style="flex: 1; padding: 12px; background-color: #B8D4E3; color: #333; border: none; cursor: pointer; font-size: 14px; font-weight: bold; transition: background-color 0.3s;">
                                        Add Neighbors
                                    </button>
                                </div>
                                
                                <!-- Content -->
                                <div style="padding: 20px; max-height: 400px; overflow-y: auto; background-color: #FFF9E6;">
                                    <!-- Pending Requests Tab -->
                                    <div id="pendingContent" class="tab-content">
                                        <div id="pendingList" style="display: flex; flex-direction: column; gap: 10px;">
                                            <p style="text-align: center; color: #7F8C8D; font-style: italic;">Loading requests...</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Current Neighbors Tab -->
                                    <div id="currentContent" class="tab-content" style="display: none;">
                                        <div id="currentList" style="display: flex; flex-direction: column; gap: 10px;">
                                            <p style="text-align: center; color: #7F8C8D; font-style: italic;">Loading neighbors...</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Find Neighbors Tab -->
                                    <div id="findContent" class="tab-content" style="display: none;">
                                        <div style="margin-bottom: 15px;">
                                            <input type="text" id="searchNeighbor" placeholder="Search by name or ID..." style="width: 100%; padding: 10px; border: 2px solid #7FB3D5; border-radius: 5px; font-size: 14px; box-sizing: border-box;" onkeyup="filterPotentialNeighbors()">
                                        </div>
                                        <div id="findList" style="display: flex; flex-direction: column; gap: 10px;">
                                            <p style="text-align: center; color: #7F8C8D; font-style: italic;">Loading users...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <style>
                            .neighbor-item {
                                display: flex;
                                align-items: center;
                                justify-content: space-between;
                                padding: 12px 15px;
                                background: white;
                                border: 2px solid #D5E8F0;
                                border-radius: 8px;
                                transition: all 0.3s;
                            }
                            
                            .neighbor-item:hover {
                                border-color: #7FB3D5;
                                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                                transform: translateY(-2px);
                            }
                            
                            .neighbor-info {
                                display: flex;
                                align-items: center;
                                gap: 12px;
                            }
                            
                            .neighbor-avatar {
                                width: 45px;
                                height: 45px;
                                border-radius: 50%;
                                background: linear-gradient(135deg, #7FB3D5 0%, #5C9FCC 100%);
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                color: white;
                                font-weight: bold;
                                font-size: 18px;
                                border: 2px solid #5C9FCC;
                            }
                            
                            .neighbor-name {
                                font-weight: bold;
                                color: #2C3E50;
                                font-size: 15px;
                            }
                            
                            .neighbor-id {
                                font-size: 12px;
                                color: #7F8C8D;
                            }
                            
                            .btn-action {
                                padding: 8px 16px;
                                border: none;
                                border-radius: 5px;
                                cursor: pointer;
                                font-size: 13px;
                                font-weight: bold;
                                transition: all 0.3s;
                                margin-left: 5px;
                            }
                            
                            .btn-accept {
                                background-color: #27AE60;
                                color: white;
                            }
                            
                            .btn-accept:hover {
                                background-color: #229954;
                                transform: scale(1.05);
                            }
                            
                            .btn-reject {
                                background-color: #E74C3C;
                                color: white;
                            }
                            
                            .btn-reject:hover {
                                background-color: #C0392B;
                                transform: scale(1.05);
                            }
                            
                            .btn-remove {
                                background-color: #E67E22;
                                color: white;
                            }
                            
                            .btn-remove:hover {
                                background-color: #D35400;
                                transform: scale(1.05);
                            }
                            
                            .btn-add {
                                background-color: #3498DB;
                                color: white;
                            }
                            
                            .btn-add:hover {
                                background-color: #2980B9;
                                transform: scale(1.05);
                            }
                            
                            .btn-action:disabled {
                                background-color: #BDC3C7;
                                cursor: not-allowed;
                                transform: none;
                            }
                            
                            #neighborModal::-webkit-scrollbar,
                            .tab-content::-webkit-scrollbar {
                                width: 8px;
                            }
                            
                            #neighborModal::-webkit-scrollbar-track,
                            .tab-content::-webkit-scrollbar-track {
                                background: #F0F0F0;
                                border-radius: 10px;
                            }
                            
                            #neighborModal::-webkit-scrollbar-thumb,
                            .tab-content::-webkit-scrollbar-thumb {
                                background: #7FB3D5;
                                border-radius: 10px;
                            }
                            
                            #neighborModal::-webkit-scrollbar-thumb:hover,
                            .tab-content::-webkit-scrollbar-thumb:hover {
                                background: #5C9FCC;
                            }

                            
                            .tooltip {
                                position: relative;
                                display: inline-block;
                            }

                            .tooltip .tooltiptext {
                                visibility: hidden;
                                width: 200px;
                                background-color: #2C3E50;
                                color: #fff;
                                text-align: center;
                                border-radius: 6px;
                                padding: 8px;
                                position: absolute;
                                z-index: 1;
                                bottom: 125%;
                                left: 50%;
                                margin-left: -100px;
                                opacity: 0;
                                transition: opacity 0.3s;
                                font-size: 12px;
                            }

                            .tooltip .tooltiptext::after {
                                content: "";
                                position: absolute;
                                top: 100%;
                                left: 50%;
                                margin-left: -5px;
                                border-width: 5px;
                                border-style: solid;
                                border-color: #2C3E50 transparent transparent transparent;
                            }

                            .tooltip:hover .tooltiptext {
                                visibility: visible;
                                opacity: 1;
                            }
                        </style>

                        <!-- Settings Modal -->
                        <div id="settingsModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6);">
                            <div style="background-color: #fefefe; margin: 10% auto; padding: 0; border: 3px solid #8B4513; width: 400px; border-radius: 10px; box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19); font-family: Arial, sans-serif;">
                                <div style="padding: 15px 20px; background: linear-gradient(to bottom, #6b7280 0%, #4b5563 100%); color: white; border-radius: 7px 7px 0 0; display: flex; justify-content: space-between; align-items: center;">
                                    <h2 style="margin: 0; font-size: 20px; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">Account Settings</h2>
                                    <span onclick="closeSettingsModal()" style="cursor: pointer; font-size: 28px; font-weight: bold; color: white; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);">&times;</span>
                                </div>
                                <div style="padding: 25px;">
                                    <div id="settingsMessage" style="display: none; padding: 10px; margin-bottom: 15px; border-radius: 5px;"></div>

                                    <!-- Profile Picture Section -->
                                    <div style="margin-bottom: 20px; text-align: center;">
                                        <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">Profile Picture</label>
                                        <div style="display: flex; align-items: center; justify-content: center; gap: 15px;">
                                            <img id="profilePicPreview"
                                                 src="{{ auth()->user()->userMeta->profile_picture ?? '' }}"
                                                 onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect fill=%22%23e5e7eb%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2258%22 text-anchor=%22middle%22 fill=%22%239CA3AF%22 font-size=%2236%22>?</text></svg>'"
                                                 style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #ddd;">
                                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                                <label for="profilePicInput" style="padding: 8px 16px; background: linear-gradient(180deg, #3b82f6, #2563eb); color: white; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 600; text-align: center;">
                                                    Choose Photo
                                                </label>
                                                <input type="file" id="profilePicInput" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" style="display: none;">
                                                @if(auth()->user()->userMeta->profile_picture)
                                                <button type="button" onclick="removeProfilePic()" style="padding: 8px 16px; background: linear-gradient(180deg, #ef4444, #dc2626); color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 600;">
                                                    Remove
                                                </button>
                                                @endif
                                            </div>
                                        </div>
                                        <p id="profilePicStatus" style="font-size: 12px; color: #666; margin-top: 8px;"></p>
                                    </div>

                                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e7eb;">

                                    <form id="settingsForm">
                                        <div style="margin-bottom: 15px;">
                                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">First Name</label>
                                            <input type="text" id="settingsFirstName" value="{{ auth()->user()->userMeta->firstName ?? '' }}" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; box-sizing: border-box;">
                                        </div>
                                        <div style="margin-bottom: 15px;">
                                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Last Name</label>
                                            <input type="text" id="settingsLastName" value="{{ auth()->user()->userMeta->lastName ?? '' }}" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; box-sizing: border-box;">
                                        </div>
                                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e5e7eb;">
                                        <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Leave password fields empty to keep current password</p>
                                        <div style="margin-bottom: 15px;">
                                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Current Password</label>
                                            <input type="password" id="settingsCurrentPassword" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; box-sizing: border-box;">
                                        </div>
                                        <div style="margin-bottom: 15px;">
                                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">New Password</label>
                                            <input type="password" id="settingsNewPassword" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; box-sizing: border-box;">
                                        </div>
                                        <div style="margin-bottom: 20px;">
                                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Confirm New Password</label>
                                            <input type="password" id="settingsConfirmPassword" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; box-sizing: border-box;">
                                        </div>
                                        <button type="submit" style="width: 100%; padding: 12px; background: linear-gradient(180deg, #10b981, #059669); color: white; border: none; border-radius: 5px; font-size: 16px; font-weight: 600; cursor: pointer;">Save Changes</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <script>
                        function openSettingsModal() {
                            document.getElementById('settingsModal').style.display = 'block';
                            document.getElementById('settingsMessage').style.display = 'none';
                            document.getElementById('settingsCurrentPassword').value = '';
                            document.getElementById('settingsNewPassword').value = '';
                            document.getElementById('settingsConfirmPassword').value = '';
                        }

                        function closeSettingsModal() {
                            document.getElementById('settingsModal').style.display = 'none';
                        }

                        document.getElementById('profilePicInput').addEventListener('change', async function(e) {
                            const file = e.target.files[0];
                            if (!file) return;

                            const statusEl = document.getElementById('profilePicStatus');
                            const preview = document.getElementById('profilePicPreview');

                            if (file.size > 5 * 1024 * 1024) {
                                statusEl.textContent = 'File must be less than 5MB';
                                statusEl.style.color = '#dc2626';
                                return;
                            }

                            const reader = new FileReader();
                            reader.onload = (e) => preview.src = e.target.result;
                            reader.readAsDataURL(file);

                            statusEl.textContent = 'Uploading...';
                            statusEl.style.color = '#666';

                            const formData = new FormData();
                            formData.append('profile_picture', file);

                            try {
                                const res = await fetch('{{ route("profile.picture.upload") }}', {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                                    body: formData
                                });
                                const data = await res.json();

                                if (data.success) {
                                    statusEl.textContent = 'Updated! Reloading...';
                                    statusEl.style.color = '#059669';
                                    setTimeout(() => location.reload(), 1000);
                                } else {
                                    statusEl.textContent = data.message || 'Upload failed';
                                    statusEl.style.color = '#dc2626';
                                }
                            } catch (err) {
                                statusEl.textContent = 'Upload failed';
                                statusEl.style.color = '#dc2626';
                            }
                        });

                        async function removeProfilePic() {
                            if (!confirm('Remove your profile picture?')) return;

                            const statusEl = document.getElementById('profilePicStatus');
                            statusEl.textContent = 'Removing...';
                            statusEl.style.color = '#666';

                            try {
                                const res = await fetch('{{ route("profile.picture.delete") }}', {
                                    method: 'DELETE',
                                    headers: {
                                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                        'Content-Type': 'application/json'
                                    }
                                });
                                const data = await res.json();

                                if (data.success) {
                                    statusEl.textContent = 'Removed! Reloading...';
                                    statusEl.style.color = '#059669';
                                    setTimeout(() => location.reload(), 1000);
                                } else {
                                    statusEl.textContent = data.message || 'Failed';
                                    statusEl.style.color = '#dc2626';
                                }
                            } catch (err) {
                                statusEl.textContent = 'Failed to remove';
                                statusEl.style.color = '#dc2626';
                            }
                        }

                        document.getElementById('settingsForm').addEventListener('submit', function(e) {
                            e.preventDefault();
                            const msgEl = document.getElementById('settingsMessage');
                            const newPass = document.getElementById('settingsNewPassword').value;
                            const confirmPass = document.getElementById('settingsConfirmPassword').value;

                            if (newPass && newPass !== confirmPass) {
                                msgEl.style.display = 'block';
                                msgEl.style.backgroundColor = '#fee2e2';
                                msgEl.style.color = '#dc2626';
                                msgEl.textContent = 'New passwords do not match';
                                return;
                            }

                            fetch('{{ route("profile.settings") }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({
                                    firstName: document.getElementById('settingsFirstName').value,
                                    lastName: document.getElementById('settingsLastName').value,
                                    current_password: document.getElementById('settingsCurrentPassword').value,
                                    new_password: newPass,
                                    new_password_confirmation: confirmPass
                                })
                            })
                            .then(res => res.json())
                            .then(data => {
                                msgEl.style.display = 'block';
                                if (data.success) {
                                    msgEl.style.backgroundColor = '#d1fae5';
                                    msgEl.style.color = '#059669';
                                    msgEl.textContent = data.message + ' Reloading...';
                                    setTimeout(function() { window.location.reload(); }, 1000);
                                } else {
                                    msgEl.style.backgroundColor = '#fee2e2';
                                    msgEl.style.color = '#dc2626';
                                    msgEl.textContent = data.message || 'An error occurred';
                                }
                            })
                            .catch(err => {
                                msgEl.style.display = 'block';
                                msgEl.style.backgroundColor = '#fee2e2';
                                msgEl.style.color = '#dc2626';
                                msgEl.textContent = 'An error occurred. Please try again.';
                            });
                        });

                        window.addEventListener('click', function(e) {
                            const modal = document.getElementById('settingsModal');
                            if (e.target === modal) closeSettingsModal();
                        });
                        </script>

                        <div>
                            <div id="innerFlashDiv" style="width: 100%; height: 100%; flex: 1;">
                                <div id="flashContent" style="width: 100%; height: 100%;">
                                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; background: linear-gradient(135deg, #2d5016 0%, #4a7c23 50%, #2d5016 100%); font-family: Arial, sans-serif; color: white; text-align: center; padding: 40px;">
                                        <div style="background: rgba(0,0,0,0.4); border-radius: 20px; padding: 40px 60px; max-width: 500px;">
                                            <h1 style="font-size: 32px; margin-bottom: 20px; text-shadow: 2px 2px 4px rgba(0,0,0,0.5);">Flash Player Not Detected</h1>
                                            <p style="font-size: 18px; margin-bottom: 30px; line-height: 1.6;">FarmVille Classic requires Flash Player to run. Please download our standalone launcher to play.</p>
                                            <a href="https://farmplay.win" style="display: inline-block; background: #4a7c23; color: white; padding: 15px 40px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 18px; border: 2px solid #fff; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)';" onmouseout="this.style.transform='scale(1)';">Download Launcher</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- PREFETCHED ASSETS -->
                            <link rel="prefetch" href="<?= $baseUrl ?>/farmville/xml/gz/v855038/gameSettings.xml.gz">
                            <link rel="prefetch" href="<?= $baseUrl ?>/farmville/xml/gz/v855038/gameSettingsCMS.xml.gz">
                            <link rel="prefetch" href="<?= $baseUrl ?>/farmville/xml/gz/v855038/FarmConfig.swf">
                            <link rel="prefetch" href="<?= $baseUrl ?>/farmville/xml/gz/v855038/en_US.swf">
                        </div>
                    </center>
        </div>
    </div>

    <!-- Earn Cash Dropdown JavaScript -->
    <script>
    function toggleEarnCashDropdown(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('earnCashDropdown');
        dropdown.classList.toggle('show');
    }

    function closeEarnCashDropdown() {
        document.getElementById('earnCashDropdown').classList.remove('show');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.earn-cash-dropdown')) {
            closeEarnCashDropdown();
        }
    });
    </script>

    <!-- Chat JavaScript -->
    @vite(['resources/js/app.js'])
    <script>
    let chatOpen = false;
    let lastMessageId = 0;

    function toggleChatDropdown(e) {
        e.stopPropagation();
        const dropdown = document.getElementById('chatDropdown');
        const isOpening = !dropdown.classList.contains('show');

        closeAccountDropdown();
        dropdown.classList.toggle('show');
        chatOpen = !chatOpen;

        if (isOpening) {
            loadChatMessages();
            updateUnreadCount();
        }
    }

    function closeChatDropdown() {
        document.getElementById('chatDropdown').classList.remove('show');
        chatOpen = false;
    }

    async function loadChatMessages(before = null) {
        try {
            const url = before ? `/chat/messages?before=${before}` : '/chat/messages';
            const response = await fetch(url, {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });
            const data = await response.json();

            const container = document.getElementById('chatMessages');
            if (!before) {
                container.innerHTML = '';
            }

            data.messages.forEach(msg => {
                appendMessage(msg);
                if (msg.id > lastMessageId) {
                    lastMessageId = msg.id;
                }
            });

            if (chatOpen && lastMessageId > 0) {
                markMessagesAsRead(lastMessageId);
            }

            scrollToBottom();
        } catch (error) {
            console.error('Failed to load messages:', error);
        }
    }

    function appendMessage(msg) {
        const container = document.getElementById('chatMessages');

        if (document.querySelector(`[data-message-id="${msg.id}"]`)) {
            console.log('⚠️ Message already exists, skipping:', msg.id);
            return;
        }

        const messageDiv = document.createElement('div');
        messageDiv.className = 'chat-message';
        messageDiv.setAttribute('data-message-id', msg.id);

        const defaultAvatar = 'https://f005.backblazeb2.com/file/fv-assets/profile-pictures/default.jpg';

        messageDiv.innerHTML = `
            <img src="${msg.profilePicture || defaultAvatar}"
                 alt="${msg.username}"
                 class="chat-message-avatar"
                 onerror="this.src='${defaultAvatar}'">
            <div class="chat-message-content">
                <div class="chat-message-username">${escapeHtml(msg.username)}</div>
                <div class="chat-message-text">${escapeHtml(msg.message)}</div>
                <div class="chat-message-time">${formatTime(msg.createdAt)}</div>
            </div>
        `;
        container.appendChild(messageDiv);
    }

    async function sendChatMessage() {
        const input = document.getElementById('chatInput');
        const message = input.value.trim();

        if (!message) return;

        try {
            const response = await fetch('/chat/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ message })
            });

            if (response.ok) {
                const data = await response.json();
                console.log('✅ Message sent:', data);

                appendMessage({
                    id: data.message.id,
                    message: data.message.message,
                    username: data.message.username,
                    profilePicture: data.message.profilePicture,
                    createdAt: data.message.createdAt
                });

                if (data.message.id > lastMessageId) {
                    lastMessageId = data.message.id;
                }

                scrollToBottom();
                markMessagesAsRead(lastMessageId);

                input.value = '';
            } else {
                console.error('Failed to send message');
                alert('Failed to send message. Please try again.');
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Error sending message. Please check your connection.');
        }
    }

    async function updateUnreadCount() {
        try {
            const response = await fetch('/chat/unread-count', {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            if (!response.ok) {
                console.error('Unread count request failed:', response.status);
                return;
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.error('Unread count response is not JSON');
                return;
            }

            const data = await response.json();

            const badge = document.getElementById('chatBadge');
            if (data.unreadCount > 0) {
                badge.textContent = data.unreadCount;
                badge.style.display = 'block';
            } else {
                badge.style.display = 'none';
            }
        } catch (error) {
            console.error('Failed to update unread count:', error);
        }
    }

    async function markMessagesAsRead(messageId) {
        try {
            await fetch('/chat/mark-read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ messageId })
            });
            updateUnreadCount();
        } catch (error) {
            console.error('Failed to mark messages as read:', error);
        }
    }

    function scrollToBottom() {
        const container = document.getElementById('chatMessages');
        container.scrollTop = container.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatTime(isoString) {
        const date = new Date(isoString);
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) return 'Just now';
        if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
        if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago';
        return date.toLocaleDateString();
    }

    document.addEventListener('DOMContentLoaded', function() {
        const chatInput = document.getElementById('chatInput');
        if (chatInput) {
            chatInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    sendChatMessage();
                }
            });
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.chat-dropdown')) {
                closeChatDropdown();
            }
        });

        updateUnreadCount();

        setInterval(updateUnreadCount, 30000);

        setTimeout(function() {
            if (window.Echo) {
                console.log('🔌 Setting up WebSocket listener for global-chat');

                window.Echo.channel('global-chat')
                    .listen('.message.sent', (e) => {
                        console.log('📨 New message received:', e);

                        appendMessage({
                            id: e.messageId,
                            message: e.message,
                            username: e.username,
                            profilePicture: e.profilePicture,
                            createdAt: e.createdAt
                        });

                        if (e.messageId > lastMessageId) {
                            lastMessageId = e.messageId;
                        }

                        if (chatOpen) {
                            scrollToBottom();
                            markMessagesAsRead(lastMessageId);
                        } else {
                            updateUnreadCount();
                        }
                    })
                    .error((error) => {
                        console.error('❌ WebSocket error:', error);
                    });

                console.log('✅ WebSocket listener registered');
            } else {
                console.error('❌ Echo not available - WebSocket will not work');
            }
        }, 1000);
    });
    </script>
</body>
</html>
