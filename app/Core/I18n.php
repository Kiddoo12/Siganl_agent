<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Internationalization (i18n) service for English (EN) and Khmer (KM - ភាសាខ្មែរ).
 */
final class I18n
{
    private static ?string $currentLang = null;

    private static array $dictionary = [
        'en' => [
            // Navigation
            'nav_terminal'       => 'Terminal',
            'nav_live'           => 'Live Market',
            'nav_vision'         => 'AI Chart Vision',
            'nav_vision_lab'     => 'Vision Lab',
            'nav_strategies'     => 'ICT Strategy Library',
            'nav_scanner'        => 'Signal Scanner',
            'nav_mtf'            => 'Multi-Timeframe',
            'nav_signals'        => 'Signals & Stats',
            'nav_backtest'       => 'Backtester',
            'nav_training'       => 'AI Training',
            'nav_calendar'       => 'Calendar',
            'nav_calculator'     => 'Risk Calculator',
            'nav_settings'       => 'Settings',
            'nav_admin'          => 'Admin',

            // Common Trading Terms
            'buy'                => 'BUY',
            'sell'               => 'SELL',
            'no_trade'           => 'NO TRADE',
            'waiting'            => 'WAITING',
            'confirmed_setup'    => 'CONFIRMED SETUP',
            'bullish'            => 'Bullish',
            'bearish'            => 'Bearish',
            'neutral'            => 'Neutral',
            'entry'              => 'Entry',
            'entry_zone'         => 'Entry Zone',
            'stop_loss'          => 'Stop Loss (SL)',
            'take_profit'        => 'Take Profit (TP)',
            'tp1'                => 'TP 1',
            'tp2'                => 'TP 2',
            'tp3'                => 'TP 3',
            'risk_reward'        => 'Risk : Reward',
            'confidence'         => 'Confidence Score',
            'killzone'           => 'Kill Zone',
            'macro'              => 'Macro Window',

            // SMC / ICT Concepts
            'order_block'        => 'Order Block (OB)',
            'breaker_block'      => 'Breaker Block',
            'fvg'                => 'Fair Value Gap (FVG)',
            'ifvg'               => 'Inverse FVG (IFVG)',
            'liquidity_sweep'    => 'Liquidity Sweep',
            'structure_shift'    => 'Market Structure Shift (MSS)',
            'bos'                => 'Break of Structure (BOS)',
            'choch'              => 'Change of Character (CHoCH)',
            'premium'            => 'Premium Zone',
            'discount'           => 'Discount Zone',
            'equilibrium'        => 'Equilibrium (50%)',

            // UI Actions
            'analyze_chart'      => 'Analyze Chart with AI Vision',
            'upload_prompt'      => 'Drop chart screenshot here or paste from clipboard (Ctrl+V)',
            'upload_btn'         => 'Choose Screenshot',
            'recent_scans'       => 'Recent Chart Analyses',
            'live_feed'          => 'LIVE FEED',
            'demo_mode'          => 'DEMO SYNTHETIC',
            'apply'              => 'Apply',
            'calculate'          => 'Calculate Position Size',
            'send_to_telegram'   => 'Send to Telegram Bot',
            'sending_to_telegram'=> 'Sending to Telegram...',
            'sent_to_telegram'   => 'Sent to Telegram!',
            'close'              => 'Close',
        ],
        'km' => [
            // Navigation
            'nav_terminal'       => 'ស្ថានីយជួញដូរ',
            'nav_live'           => 'ទីផ្សារបន្តផ្ទាល់',
            'nav_vision'         => 'វិភាគរូបភាពតារាង AI',
            'nav_vision_lab'     => 'បន្ទប់ពិសោធន៍តារាង AI',
            'nav_strategies'     => 'បណ្ណាល័យយុទ្ធសាស្ត្រ ICT',
            'nav_scanner'        => 'ឧបករណ៍ស្កេនសញ្ញា',
            'nav_mtf'            => 'វិភាគពហុពេលវេលា',
            'nav_signals'        => 'សញ្ញានិងស្ថិតិ',
            'nav_backtest'       => 'តេស្តថយក្រោយ',
            'nav_training'       => 'ការបណ្តុះបណ្តាល AI',
            'nav_calendar'       => 'ប្រតិទិនសេដ្ឋកិច្ច',
            'nav_calculator'     => 'គណនាហានិភ័យ',
            'nav_settings'       => 'ការកំណត់',
            'nav_admin'          => 'អ្នកគ្រប់គ្រង',

            // Common Trading Terms
            'buy'                => 'ទិញ (BUY)',
            'sell'               => 'លក់ (SELL)',
            'no_trade'           => 'កុំទិញដូរ (NO TRADE)',
            'waiting'            => 'រង់ចាំ (WAITING)',
            'confirmed_setup'    => 'សញ្ញាបញ្ជាក់ច្បាស់ (CONFIRMED)',
            'bullish'            => 'ទិសដៅឡើង (Bullish)',
            'bearish'            => 'ទិសដៅចុះ (Bearish)',
            'neutral'            => 'កណ្តាល (Neutral)',
            'entry'              => 'តម្លៃចូលផ្សារ',
            'entry_zone'         => 'តំបន់ចូលផ្សារ',
            'stop_loss'          => 'កាត់ខាត (SL)',
            'take_profit'        => 'យកប្រាក់ចំណេញ (TP)',
            'tp1'                => 'គោលដៅទី១ (TP1)',
            'tp2'                => 'គោលដៅទី២ (TP2)',
            'tp3'                => 'គោលដៅទី៣ (TP3)',
            'risk_reward'        => 'សមាមាត្រហានិភ័យ/ផលចំណេញ (RR)',
            'confidence'         => 'ពិន្ទុជឿជាក់',
            'killzone'           => 'តំបន់ពេលវេលាមាស (Kill Zone)',
            'macro'              => 'បង្អួចម៉ាក្រូ (Macro Window)',

            // SMC / ICT Concepts
            'order_block'        => 'ប្លុកបញ្ជាទិញ (Order Block)',
            'breaker_block'      => 'ប្លុកបំបែក (Breaker Block)',
            'fvg'                => 'គម្លាតតម្លៃសមរម្យ (FVG)',
            'ifvg'               => 'គម្លាតតម្លៃបញ្ច្រាស (IFVG)',
            'liquidity_sweep'    => 'ការស្រូបយកសន្ទនីយភាព (Liquidity Sweep)',
            'structure_shift'    => 'ការផ្លាស់ប្តូររចនាសម្ព័ន្ធ (MSS)',
            'bos'                => 'ការបំបែករចនាសម្ព័ន្ធ (BOS)',
            'choch'              => 'ការផ្លាស់ប្តូរចរិតលក្ខណៈ (CHoCH)',
            'premium'            => 'តំបន់ថ្លៃ (Premium Zone)',
            'discount'           => 'តំបន់ថោក (Discount Zone)',
            'equilibrium'        => 'ចំណុចកណ្តាល ៥០% (Equilibrium)',

            // UI Actions
            'analyze_chart'      => 'វិភាគរូបភាពតារាងជាមួយ AI Vision',
            'upload_prompt'      => 'ទម្លាក់រូបភាពតារាងទីនេះ ឬបិទភ្ជាប់ពីក្តារតម្បៀតខ្ទាស់ (Ctrl+V)',
            'upload_btn'         => 'ជ្រើសរើសរូបភាព',
            'recent_scans'       => 'ប្រវត្តិវិភាគរូបភាពកន្លងមក',
            'live_feed'          => 'ទិន្នន័យផ្សាយផ្ទាល់',
            'demo_mode'          => 'ទម្រង់សាកល្បង',
            'apply'              => 'អនុវត្ត',
            'calculate'          => 'គណនាទំហំឡូត៍ (Lots)',
            'send_to_telegram'   => 'ផ្ញើទៅតេឡេក្រាមបូត (Telegram)',
            'sending_to_telegram'=> 'កំពុងផ្ញើទៅតេឡេក្រាម...',
            'sent_to_telegram'   => 'បានផ្ញើទៅតេឡេក្រាមរួចរាល់!',
            'close'              => 'បិទ',
        ],
    ];

    public static function lang(): string
    {
        if (self::$currentLang !== null) {
            return self::$currentLang;
        }
        $lang = 'en';
        if (!empty($_COOKIE['app_lang']) && in_array(strtolower($_COOKIE['app_lang']), ['en', 'km'], true)) {
            $lang = strtolower($_COOKIE['app_lang']);
        } elseif (!empty($_GET['lang']) && in_array(strtolower($_GET['lang']), ['en', 'km'], true)) {
            $lang = strtolower($_GET['lang']);
            @setcookie('app_lang', $lang, time() + 31536000, '/');
        }
        self::$currentLang = $lang;
        return $lang;
    }

    public static function setLang(string $lang): void
    {
        if (in_array(strtolower($lang), ['en', 'km'], true)) {
            self::$currentLang = strtolower($lang);
            @setcookie('app_lang', self::$currentLang, time() + 31536000, '/');
        }
    }

    public static function t(string $key, ?string $lang = null): string
    {
        $l = $lang ?: self::lang();
        return self::$dictionary[$l][$key] ?? (self::$dictionary['en'][$key] ?? $key);
    }

    public static function allTranslations(): array
    {
        return self::$dictionary;
    }
}
