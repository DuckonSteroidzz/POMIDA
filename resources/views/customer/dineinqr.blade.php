<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dine In - Peachy</title>
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
            background-color: #F4C2A8;
            min-height: 100vh;
            display: flex;
            justify-content: center;
        }

        .app-wrapper {
            width: 100%;
            max-width: 420px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Header */
        .top-header {
            padding: 1.25rem 1rem 0.75rem;
            text-align: center;
        }

        .logo-circle {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin: 0 auto 0.3rem;
        }

        .brand-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #F4845F;
            font-style: italic;
        }

        .brand-tagline {
            font-size: 0.72rem;
            color: #999;
        }

        /* Scanner Section */
        .scanner-section {
            flex: 1;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem;
        }

        .scanner-frame {
            width: 260px;
            height: 260px;
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            background: #111;
            margin-bottom: 1rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        }

        #qrVideo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Corner borders */
        .corner {
            position: absolute;
            width: 30px;
            height: 30px;
            border-color: #F4845F;
            border-style: solid;
            z-index: 10;
        }

        .corner.tl {
            top: 12px;
            left: 12px;
            border-width: 3px 0 0 3px;
            border-radius: 4px 0 0 0;
        }

        .corner.tr {
            top: 12px;
            right: 12px;
            border-width: 3px 3px 0 0;
            border-radius: 0 4px 0 0;
        }

        .corner.bl {
            bottom: 12px;
            left: 12px;
            border-width: 0 0 3px 3px;
            border-radius: 0 0 0 4px;
        }

        .corner.br {
            bottom: 12px;
            right: 12px;
            border-width: 0 3px 3px 0;
            border-radius: 0 0 4px 0;
        }

        /* Scan line */
        .scan-line {
            position: absolute;
            left: 12px;
            right: 12px;
            height: 2px;
            background: #F4845F;
            opacity: 0.8;
            z-index: 10;
            animation: scanMove 2s linear infinite;
        }

        @keyframes scanMove {
            0% {
                top: 15%;
            }

            50% {
                top: 80%;
            }

            100% {
                top: 15%;
            }
        }

        .scan-text {
            font-size: 0.85rem;
            color: #555;
            text-align: center;
            margin-bottom: 1.25rem;
            font-weight: 500;
        }

        .scan-btn {
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
            margin-bottom: 1rem;
            width: 80%;
            max-width: 280px;
        }

        .scan-btn:hover {
            transform: scale(1.02);
        }

        .scan-btn:disabled {
            background: #ccc;
            box-shadow: none;
            cursor: not-allowed;
        }

        .scan-btn.scanning {
            background: linear-gradient(135deg, #4CAF50, #388E3C);
        }

        .status-msg {
            font-size: 0.75rem;
            color: #F4845F;
            font-weight: 600;
            text-align: center;
            margin-bottom: 0.75rem;
            min-height: 1.2rem;
        }

        /* Bottom Section */
        .bottom-section {
            width: 100%;
            padding: 1rem 1.5rem 2rem;
            text-align: center;
        }

        .bottom-section a {
            color: #555;
            font-size: 0.82rem;
            text-decoration: none;
            font-weight: 500;
        }

        .bottom-section a:hover {
            color: #F4845F;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            color: #F4845F;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.82rem;
            margin-top: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="app-wrapper">

        {{-- Header --}}
        <div class="top-header">
            <div class="logo-circle">🍑</div>
            <p class="brand-name">Peachy</p>
            <p class="brand-tagline">Cakes and Deli Cafe</p>
        </div>

        {{-- Scanner --}}
        <div class="scanner-section">
            <div class="scanner-frame">
                <video id="qrVideo" autoplay playsinline muted></video>
                <div class="corner tl"></div>
                <div class="corner tr"></div>
                <div class="corner bl"></div>
                <div class="corner br"></div>
                <div class="scan-line"></div>
            </div>

            <p class="scan-text">Align QR code within frame to scan</p>

            <p class="status-msg" id="statusMsg"></p>

            <button class="scan-btn" id="scanBtn" onclick="startScan()">
                <i class="bi bi-qr-code-scan"></i> SCAN QR CODE
            </button>
        </div>

        {{-- Bottom --}}
        <div class="bottom-section">
            <a href="{{ route('customer.login') }}">Want to Log in?</a>
            <br>
            <a href="/" class="back-btn"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        {{-- Hidden form --}}
        <form action="{{ route('customer.qr.process') }}" method="POST" id="qrForm" style="display:none;">
            @csrf
            <input type="hidden" name="table_data" id="tableData">
        </form>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <script>
        var scanning = false;
        var stream = null;
        var scanInterval = null;

        function startScan() {
            if (scanning) return;

            var btn = document.getElementById('scanBtn');
            var msg = document.getElementById('statusMsg');
            btn.textContent = 'Scanning...';
            btn.disabled = true;
            btn.classList.add('scanning');
            msg.textContent = 'Opening camera...';

            navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment',
                        width: {
                            ideal: 640
                        },
                        height: {
                            ideal: 640
                        }
                    }
                })
                .then(function(s) {
                    stream = s;
                    var video = document.getElementById('qrVideo');
                    video.srcObject = stream;
                    scanning = true;
                    msg.textContent = 'Point at QR code...';

                    video.onloadedmetadata = function() {
                        video.play();
                        scanInterval = setInterval(function() {
                            scanFrame(video);
                        }, 300);
                    };
                })
                .catch(function(err) {
                    btn.textContent = 'SCAN QR CODE';
                    btn.disabled = false;
                    btn.classList.remove('scanning');
                    msg.textContent = '';

                    if (err.name === 'NotAllowedError') {
                        alert('Camera access denied. Please allow camera permission in your browser settings.');
                    } else if (err.name === 'NotFoundError') {
                        alert('No camera found on this device.');
                    } else {
                        alert('Camera error: ' + err.message);
                    }
                });
        }

        function scanFrame(video) {
            if (!scanning) return;
            if (video.readyState !== video.HAVE_ENOUGH_DATA) return;

            var canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: 'dontInvert'
            });

            if (code) {
                // QR detected!
                scanning = false;
                if (scanInterval) clearInterval(scanInterval);
                if (stream) stream.getTracks().forEach(function(t) {
                    t.stop();
                });

                var msg = document.getElementById('statusMsg');
                msg.textContent = '✓ QR Code detected! Redirecting...';
                msg.style.color = '#4CAF50';

                var url = code.data;

                // Check if it's a valid Peachy URL with table parameter
                if (url.indexOf('/customer/menu') !== -1 && url.indexOf('table=') !== -1) {
                    // Direct redirect to the menu URL
                    setTimeout(function() {
                        window.location.href = url;
                    }, 500);
                } else {
                    // Submit via form for server-side processing
                    document.getElementById('tableData').value = url;
                    document.getElementById('qrForm').submit();
                }
            }
        }

        // Auto-start camera on page load (mobile-friendly)
        window.addEventListener('load', function() {
            setTimeout(function() {
                startScan();
            }, 500);
        });
    </script>
</body>

</html>