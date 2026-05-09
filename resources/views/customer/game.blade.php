<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spin & Win - Peachy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #e8e8e8;
            min-height: 100vh;
            display: flex;
            justify-content: center;
        }

        .app-wrapper {
            width: 100%;
            max-width: 420px;
            min-height: 100vh;
            background-color: #fff;
            display: flex;
            flex-direction: column;
            padding-bottom: 80px;
        }

        .top-header {
            background-color: #fff;
            padding: 0.75rem 1rem 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
        }

        .logo-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-color: rgba(244, 132, 95, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .business-info h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #F4845F;
            margin: 0;
        }

        .business-info p {
            font-size: 0.65rem;
            color: #aaa;
            margin: 0;
        }

        .order-banner {
            background: linear-gradient(135deg, #F4845F, #C0392B);
            color: white;
            padding: 0.75rem 1rem;
            text-align: center;
            font-size: 0.82rem;
            font-weight: 600;
        }

        .order-banner span {
            display: block;
            font-size: 0.72rem;
            font-weight: 400;
            opacity: 0.9;
            margin-top: 0.2rem;
        }

        .points-bar {
            background: #fff9f6;
            border-bottom: 1px solid #fde8de;
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .points-label {
            font-size: 0.78rem;
            color: #888;
        }

        .points-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #F4845F;
        }

        .game-section {
            flex: 1;
            padding: 1.25rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .game-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.25rem;
            text-align: center;
        }

        .game-subtitle {
            font-size: 0.8rem;
            color: #aaa;
            margin-bottom: 1.25rem;
            text-align: center;
        }

        .wheel-container {
            position: relative;
            width: 280px;
            height: 280px;
            margin-bottom: 1.25rem;
        }

        canvas {
            border-radius: 50%;
            box-shadow: 0 8px 32px rgba(244, 132, 95, 0.3);
        }

        .wheel-pointer {
            position: absolute;
            top: -18px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 2rem;
            z-index: 10;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
        }

        .spin-btn {
            background: linear-gradient(135deg, #F4845F, #C0392B);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.85rem 3rem;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            box-shadow: 0 4px 15px rgba(244, 132, 95, 0.4);
            transition: transform 0.2s;
            margin-bottom: 0.75rem;
        }

        .spin-btn:hover {
            transform: scale(1.05);
        }

        .spin-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .skip-btn {
            background: none;
            border: none;
            color: #aaa;
            font-size: 0.78rem;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            text-decoration: underline;
            margin-bottom: 0.5rem;
        }

        .result-box {
            display: none;
            background: linear-gradient(135deg, #fff9f6, #fde8de);
            border: 2px solid #F4845F;
            border-radius: 16px;
            padding: 1.25rem;
            text-align: center;
            width: 100%;
            margin-top: 0.5rem;
        }

        .result-box.win {
            border-color: #4CAF50;
            background: linear-gradient(135deg, #f6fff6, #d4edda);
        }

        .result-box.lose {
            border-color: #ddd;
            background: #f9f9f9;
        }

        .result-emoji {
            font-size: 2.5rem;
            display: block;
            margin-bottom: 0.5rem;
        }

        .result-title {
            font-size: 1rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .result-code {
            font-size: 1.4rem;
            font-weight: 700;
            color: #F4845F;
            letter-spacing: 2px;
            background: white;
            border: 2px dashed #F4845F;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            display: inline-block;
            margin: 0.5rem 0;
        }

        .result-desc {
            font-size: 0.78rem;
            color: #666;
        }

        .copy-btn {
            background: #F4845F;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.45rem 1rem;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            margin-top: 0.5rem;
        }

        .check-order-btn {
            display: block;
            background: #2e2e2e;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem 1.5rem;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
            text-align: center;
            margin-top: 0.75rem;
            width: 100%;
        }

        .disabled-game {
            text-align: center;
            padding: 3rem 1rem;
            color: #aaa;
        }

        .disabled-game i {
            font-size: 3rem;
            display: block;
            margin-bottom: 0.75rem;
            color: #ddd;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            width: 100%;
            max-width: 420px;
            background-color: #fff;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 0.5rem 0 0.4rem;
            z-index: 100;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            text-decoration: none;
            color: #aaa;
            font-size: 0.65rem;
            font-weight: 500;
        }

        .nav-item.active {
            color: #F4845F;
        }

        .nav-item i {
            font-size: 1.3rem;
        }
    </style>
</head>

<body>
    <div class="app-wrapper">
        <div class="top-header">
            <div class="logo-circle">🍑</div>
            <div class="business-info">
                <h2>Peachy</h2>
                <p>Cakes and Deli Cafe</p>
            </div>
        </div>

        @if(session('order_placed'))
        <div class="order-banner">
            🎉 {{ session('order_placed') }}
            <span>Order #{{ session('order_number') }}</span>
        </div>
        @endif

        <div class="points-bar">
            <span class="points-label">⭐ Your Points</span>
            <span class="points-value" id="pointsDisplay">{{ Auth::check() ? auth()->user()->points : session('guest_points', 0) }} pts</span>
        </div>

        <div class="game-section">
            @php
            $gameEnabled = \Illuminate\Support\Facades\DB::table('settings')->where('key', 'game_enabled')->value('value');
            @endphp

            @if($gameEnabled === '1')
            <p class="game-title">🎰 Spin & Win!</p>
            <p class="game-subtitle">Spin for a chance to earn points & win vouchers!</p>

            <div class="wheel-container">
                <div class="wheel-pointer">▼</div>
                <canvas id="wheelCanvas" width="280" height="280"></canvas>
            </div>

            <button class="spin-btn" id="spinBtn" onclick="spinWheel()">
                <i class="bi bi-arrow-repeat"></i> SPIN!
            </button>

            <button class="skip-btn" onclick="goToOrders()">Skip — Check my order</button>

            <div class="result-box" id="resultBox">
                <span class="result-emoji" id="resultEmoji">⭐</span>
                <p class="result-title" id="resultTitle"></p>
                <div id="voucherResult"></div>
                <p class="result-desc" id="resultDesc"></p>
                <button class="copy-btn" id="copyBtn" onclick="copyCode()" style="display:none;">
                    <i class="bi bi-clipboard"></i> Copy Code
                </button>
                <a href="{{ route('customer.orders') }}" class="check-order-btn">
                    <i class="bi bi-receipt"></i> Check My Order
                </a>
            </div>
            @else
            <div class="disabled-game">
                <i class="bi bi-dice-5"></i>
                <p style="font-size:0.95rem;font-weight:600;color:#555;">Game is currently unavailable</p>
                <p style="font-size:0.78rem;">Check back later!</p>
                <a href="{{ route('customer.orders') }}" class="check-order-btn" style="margin-top:1.5rem;">
                    <i class="bi bi-receipt"></i> Check My Order
                </a>
            </div>
            @endif
        </div>

        <div class="bottom-nav">
            <a href="{{ route('customer.orders') }}" class="nav-item">
                <i class="bi bi-receipt"></i><span>Orders</span>
            </a>
            <a href="{{ route('customer.menu') }}" class="nav-item">
                <i class="bi bi-grid"></i><span>Menu</span>
            </a>
            <a href="{{ route('customer.cart') }}" class="nav-item" style="position:relative;">
                <i class="bi bi-cart"></i>
                @php $cartCount = count(session('cart', [])); @endphp
                @if($cartCount > 0)
                <span style="position:absolute;top:-4px;right:-4px;background:#C0392B;color:white;border-radius:50%;width:16px;height:16px;font-size:0.55rem;font-weight:700;display:flex;align-items:center;justify-content:center;">{{ $cartCount }}</span>
                @endif
                <span>Cart</span>
            </a>
            <a href="{{ route('customer.game') }}" class="nav-item active">
                <i class="bi bi-dice-5"></i><span>Game</span>
            </a>
            <a href="{{ route('customer.more') }}" class="nav-item">
                <i class="bi bi-three-dots"></i><span>More</span>
            </a>
        </div>
    </div>

    <script>
        var gameAds = @json($gameAds);

        var segments = [{
                type: 'points',
                points: 3,
                label: '3 pts'
            },
            {
                type: 'points',
                points: 5,
                label: '5 pts'
            },
            {
                type: 'points',
                points: 3,
                label: '3 pts'
            },
            {
                type: 'points',
                points: 8,
                label: '8 pts'
            },
            {
                type: 'points',
                points: 3,
                label: '3 pts'
            },
            {
                type: 'points',
                points: 5,
                label: '5 pts'
            },
            {
                type: 'lose',
                points: 0,
                label: 'Try Again'
            },
            {
                type: 'lose',
                points: 0,
                label: 'Try Again'
            }
        ];

        for (var i = segments.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var temp = segments[i];
            segments[i] = segments[j];
            segments[j] = temp;
        }

        var colors = ['#F4845F', '#C0392B', '#E67E22', '#E74C3C', '#D35400', '#922B21', '#CB4335', '#A93226'];
        var canvas = document.getElementById('wheelCanvas');
        var ctx = canvas ? canvas.getContext('2d') : null;
        var numSegments = segments.length;
        var arc = (2 * Math.PI) / numSegments;
        var currentAngle = 0;
        var isSpinning = false;
        var spinCount = 0;

        function drawWheel(angle) {
            if (!ctx) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            var cx = canvas.width / 2;
            var cy = canvas.height / 2;
            var radius = cx - 5;

            for (var i = 0; i < segments.length; i++) {
                var sa = angle + i * arc;
                var ea = sa + arc;
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, radius, sa, ea);
                ctx.closePath();
                ctx.fillStyle = colors[i % colors.length];
                ctx.fill();
                ctx.strokeStyle = 'white';
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.save();
                ctx.translate(cx, cy);
                ctx.rotate(sa + arc / 2);
                ctx.textAlign = 'right';
                ctx.fillStyle = 'white';
                ctx.font = 'bold 12px Poppins';
                ctx.fillText(segments[i].label, radius - 10, 4);
                ctx.restore();
            }

            ctx.beginPath();
            ctx.arc(cx, cy, 22, 0, 2 * Math.PI);
            ctx.fillStyle = 'white';
            ctx.fill();
            ctx.strokeStyle = '#F4845F';
            ctx.lineWidth = 3;
            ctx.stroke();
            ctx.font = '16px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('🍑', cx, cy + 6);
        }

        window.addEventListener('load', function() {
            setTimeout(function() {
                drawWheel(currentAngle);
            }, 150);
        });

        function spinWheel() {
            if (isSpinning) return;
            isSpinning = true;
            spinCount++;
            document.getElementById('spinBtn').disabled = true;
            document.getElementById('resultBox').style.display = 'none';

            var extra = (Math.floor(Math.random() * 5) + 5) * 2 * Math.PI;
            var stop = Math.random() * 2 * Math.PI;
            var total = extra + stop;
            var duration = 4000;
            var startTime = performance.now();
            var startAngle = currentAngle;

            function animate(now) {
                var elapsed = now - startTime;
                var progress = Math.min(elapsed / duration, 1);
                currentAngle = startAngle + total * (1 - Math.pow(1 - progress, 4));
                drawWheel(currentAngle);
                if (progress < 1) {
                    requestAnimationFrame(animate);
                } else {
                    currentAngle = startAngle + total;
                    drawWheel(currentAngle);
                    isSpinning = false;
                    showResult();
                }
            }
            requestAnimationFrame(animate);
        }

        function showResult() {
            var norm = ((currentAngle % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI);
            var ptr = (3 * Math.PI / 2);
            var segA = ((ptr - norm) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI);
            var idx = Math.floor(segA / arc) % numSegments;
            var won = segments[idx];
            var rb = document.getElementById('resultBox');
            rb.style.display = 'block';

            if (won.type === 'points') {
                fetch('/customer/add-points', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            points: won.points
                        })
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        rb.className = 'result-box win';
                        document.getElementById('resultEmoji').textContent = '⭐';
                        document.getElementById('resultTitle').textContent = '+' + won.points + ' Points!';

                        var pointsEl = document.getElementById('pointsDisplay');
                        if (pointsEl) {
                            pointsEl.textContent = data.total_points + ' pts';
                        }

                        document.getElementById('voucherResult').innerHTML =
                            '<div style="font-size:1.2rem;font-weight:700;color:#F4845F;margin:0.5rem 0;">Total: ' + data.total_points + ' pts</div>';

                        if (data.voucher) {
                            document.getElementById('resultDesc').innerHTML =
                                '🎉 You earned a voucher!<br>' +
                                '<div class="result-code" id="wonCode">' + data.voucher.code + '</div><br>' +
                                '<small>' + (data.voucher.description || '') + '</small><br>' +
                                '<small style="color:#E67E22;font-weight:600;">📅 ' + (data.voucher.message || '') + '</small><br>' +
                                '<small style="color:#888;">Points deducted automatically</small>';
                            document.getElementById('copyBtn').style.display = 'inline-block';
                            setTimeout(function() {
                                document.getElementById('spinBtn').disabled = false;
                            }, 3000);
                        } else {
                            document.getElementById('resultDesc').textContent = data.next_voucher ?
                                data.points_needed + ' more pts to win: ' + data.next_voucher + '!' :
                                'Keep spinning to earn more points!';
                            document.getElementById('copyBtn').style.display = 'none';
                            setTimeout(function() {
                                rb.style.display = 'none';
                                document.getElementById('spinBtn').disabled = false;
                            }, 2000);
                        }

                        if (spinCount % 5 === 0) {
                            setTimeout(function() {
                                showAdPopup();
                            }, 2500);
                        }
                    })
                    .catch(function() {
                        rb.className = 'result-box win';
                        document.getElementById('resultEmoji').textContent = '⭐';
                        document.getElementById('resultTitle').textContent = '+' + won.points + ' Points!';
                        document.getElementById('voucherResult').innerHTML = '';
                        document.getElementById('resultDesc').textContent = 'Keep spinning!';
                        document.getElementById('copyBtn').style.display = 'none';
                        setTimeout(function() {
                            rb.style.display = 'none';
                            document.getElementById('spinBtn').disabled = false;
                        }, 2000);
                        if (spinCount % 5 === 0) {
                            setTimeout(function() {
                                showAdPopup();
                            }, 2500);
                        }
                    });
            } else {
                rb.className = 'result-box lose';
                document.getElementById('resultEmoji').textContent = '😅';
                document.getElementById('resultTitle').textContent = 'Try Again!';
                document.getElementById('voucherResult').innerHTML = '';
                document.getElementById('resultDesc').textContent = 'Better luck next time!';
                document.getElementById('copyBtn').style.display = 'none';
                setTimeout(function() {
                    rb.style.display = 'none';
                    document.getElementById('spinBtn').disabled = false;
                }, 1500);
                if (spinCount % 5 === 0) {
                    setTimeout(function() {
                        showAdPopup();
                    }, 2000);
                }
            }
        }

        function copyCode() {
            var code = document.getElementById('wonCode').textContent;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function() {
                    document.getElementById('copyBtn').innerHTML = '✓ Copied!';
                    setTimeout(function() {
                        document.getElementById('copyBtn').innerHTML = '<i class="bi bi-clipboard"></i> Copy Code';
                        document.getElementById('resultBox').style.display = 'none';
                        document.getElementById('spinBtn').disabled = false;
                    }, 1500);
                });
            } else {
                var textArea = document.createElement('textarea');
                textArea.value = code;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                document.getElementById('copyBtn').innerHTML = '✓ Copied!';
                setTimeout(function() {
                    document.getElementById('copyBtn').innerHTML = '<i class="bi bi-clipboard"></i> Copy Code';
                    document.getElementById('resultBox').style.display = 'none';
                    document.getElementById('spinBtn').disabled = false;
                }, 1500);
            }
        }

        function goToOrders() {
            window.location.href = '{{ route("customer.orders") }}';
        }

        function showAdPopup() {
            if (gameAds.length === 0) return;
            var currentSlide = 0;

            var overlay = document.createElement('div');
            overlay.id = 'adOverlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;';

            function buildSlide(slideIdx) {
                var ad = gameAds[slideIdx];
                var dots = '';
                for (var i = 0; i < gameAds.length; i++) {
                    dots += '<span style="width:8px;height:8px;border-radius:50%;display:inline-block;background:' + (i === slideIdx ? '#F4845F' : '#ddd') + ';"></span>';
                }
                return '<div style="background:white;border-radius:12px;max-width:340px;width:90%;position:relative;overflow:hidden;">' +
                    '<button onclick="closeAd()" style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.5);color:white;border:none;border-radius:50%;width:28px;height:28px;font-size:1rem;cursor:pointer;z-index:10;display:flex;align-items:center;justify-content:center;">✕</button>' +
                    (ad.image ? '<img src="/' + ad.image + '" style="width:100%;max-height:250px;object-fit:cover;border-radius:12px 12px 0 0;">' : '') +
                    '<div style="padding:1rem;text-align:center;">' +
                    '<p style="font-size:0.95rem;font-weight:700;color:#F4845F;margin:0 0 0.25rem;">' + ad.title + '</p>' +
                    '<p style="font-size:0.78rem;color:#666;margin:0;">' + (ad.description || '') + '</p>' +
                    '</div>' +
                    (gameAds.length > 1 ? '<div style="display:flex;justify-content:center;gap:6px;padding:0 1rem 0.75rem;">' + dots + '</div>' : '') +
                    '</div>';
            }

            overlay.innerHTML = buildSlide(0);
            document.body.appendChild(overlay);

            var slideInterval = null;
            if (gameAds.length > 1) {
                slideInterval = setInterval(function() {
                    currentSlide = (currentSlide + 1) % gameAds.length;
                    var container = document.getElementById('adOverlay');
                    if (container) {
                        container.innerHTML = buildSlide(currentSlide);
                    } else {
                        clearInterval(slideInterval);
                    }
                }, 4000);
            }

            var totalTime = gameAds.length > 1 ? (gameAds.length * 4000 + 1000) : 5000;
            setTimeout(function() {
                if (slideInterval) clearInterval(slideInterval);
                closeAd();
            }, totalTime);
        }

        function closeAd() {
            var overlay = document.getElementById('adOverlay');
            if (overlay) overlay.remove();
        }
    </script>
</body>

</html>