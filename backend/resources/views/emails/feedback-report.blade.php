<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>[動漫庫] 問題回報</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f5f7; color: #30343b; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans TC', sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f3f5f7;">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; max-width: 720px; border: 1px solid #e1e5e9; background-color: #ffffff;">
                    <tr>
                        <td align="center" style="padding: 34px 24px 30px; background-color: #16a34a; color: #ffffff;">
                            <div style="font-size: 30px; font-weight: 700; line-height: 1.25;">Anime Library</div>
                            <div style="padding-top: 10px; font-size: 17px; line-height: 1.5;">動漫庫・問題回報通知</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 36px 32px 40px;">
                            <h1 style="margin: 0 0 24px; color: #15803d; font-size: 25px; line-height: 1.4;">有新的問題回報待處理</h1>
                            <p style="margin: 0 0 14px; font-size: 16px; line-height: 1.8;">管理員您好：</p>
                            <p style="margin: 0 0 24px; font-size: 16px; line-height: 1.8;">收到一筆新的使用者回報，內容如下。</p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse: collapse; font-size: 15px; line-height: 1.6;">
                                <tr>
                                    <td width="150" style="width: 150px; padding: 12px 14px; border: 1px solid #d9dee3; background-color: #f6f8fa; font-weight: 600;">回報類別</td>
                                    <td style="padding: 12px 14px; border: 1px solid #d9dee3;">{{ $category }}</td>
                                </tr>
                                <tr>
                                    <td width="150" style="width: 150px; padding: 12px 14px; border: 1px solid #d9dee3; background-color: #f6f8fa; font-weight: 600;">主旨</td>
                                    <td style="padding: 12px 14px; border: 1px solid #d9dee3;">{{ $subject }}</td>
                                </tr>
                                <tr>
                                    <td width="150" style="width: 150px; padding: 12px 14px; border: 1px solid #d9dee3; background-color: #f6f8fa; font-weight: 600;">回報者</td>
                                    <td style="padding: 12px 14px; border: 1px solid #d9dee3;">{{ $reporterName }}</td>
                                </tr>
                                <tr>
                                    <td width="150" style="width: 150px; padding: 12px 14px; border: 1px solid #d9dee3; background-color: #f6f8fa; font-weight: 600;">電子郵件</td>
                                    <td style="padding: 12px 14px; border: 1px solid #d9dee3;">
                                        <a href="mailto:{{ $reporterEmail }}" style="color: #15803d;">{{ $reporterEmail }}</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="150" style="width: 150px; padding: 12px 14px; border: 1px solid #d9dee3; background-color: #f6f8fa; font-weight: 600;">使用者 ID</td>
                                    <td style="padding: 12px 14px; border: 1px solid #d9dee3;">{{ $userId }}</td>
                                </tr>
                                <tr>
                                    <td width="150" style="width: 150px; padding: 12px 14px; border: 1px solid #d9dee3; background-color: #f6f8fa; font-weight: 600;">回報時間</td>
                                    <td style="padding: 12px 14px; border: 1px solid #d9dee3;">{{ $submittedAt }}</td>
                                </tr>
                            </table>

                            <div style="margin-top: 28px;">
                                <h2 style="margin: 0 0 10px; font-size: 17px; line-height: 1.5;">回報內容</h2>
                                <div style="padding: 16px; border-left: 4px solid #16a34a; background-color: #f6f8fa; font-size: 15px; line-height: 1.8; white-space: pre-wrap; word-break: break-word;">{{ $message }}</div>
                            </div>

                            <p style="margin: 28px 0 0; font-size: 15px; line-height: 1.8;">直接回覆此信即可聯絡回報者。</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 22px 20px; border-top: 1px solid #e1e5e9; background-color: #f3f4f5; color: #858b91; font-size: 13px; line-height: 1.8;">
                            此為動漫庫系統自動發送郵件。<br>
                            © {{ $year }} Anime Library・動漫庫
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
