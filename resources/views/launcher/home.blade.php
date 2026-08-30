<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FV Classic</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c23 50%, #6b8e23 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        .container {
            text-align: center;
            padding: 40px;
        }

        .logo {
            margin-bottom: 40px;
        }

        .logo h1 {
            font-size: 64px;
            color: #fff;
            text-shadow: 3px 3px 6px rgba(0, 0, 0, 0.4);
            font-weight: bold;
            letter-spacing: 2px;
        }

        .logo .subtitle {
            font-size: 24px;
            color: #d4e7c5;
            margin-top: 10px;
        }

        .auth-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 20px 60px;
            font-size: 24px;
            font-weight: bold;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
            min-width: 200px;
        }

        .btn-login {
            background: linear-gradient(180deg, #4CAF50 0%, #388E3C 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.4);
        }

        .btn-login:hover {
            background: linear-gradient(180deg, #5CBF60 0%, #43A047 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.5);
        }

        .btn-register {
            background: linear-gradient(180deg, #FF9800 0%, #F57C00 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.4);
        }

        .btn-register:hover {
            background: linear-gradient(180deg, #FFB74D 0%, #FF9800 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 152, 0, 0.5);
        }

        .decorations {
            position: fixed;
            pointer-events: none;
        }

        .decoration-left {
            left: 20px;
            bottom: 20px;
            font-size: 80px;
            opacity: 0.3;
        }

        .decoration-right {
            right: 20px;
            bottom: 20px;
            font-size: 80px;
            opacity: 0.3;
        }

        @media (max-width: 600px) {
            .logo h1 {
                font-size: 36px;
            }

            .btn {
                padding: 15px 40px;
                font-size: 18px;
                min-width: 150px;
            }

            .auth-buttons {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h1>FV Classic</h1>
            <p class="subtitle">An unofficial community preservation project</p>
        </div>

        <x-unofficial-notice />

        <div class="auth-buttons">
            <a href="{{ route('login') }}" class="btn btn-login">Login</a>
            <a href="{{ route('register') }}" class="btn btn-register">Register</a>
        </div>
    </div>
</body>
</html>
