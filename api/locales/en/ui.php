<?php

/**
 * VELORA — English Translation File
 * 
 * This file contains all static UI translations for English language.
 * Organized by category for easy maintenance.
 * 
 * Usage:
 *   $locale->t('common.save')        // Returns: Save
 *   $locale->t('dashboard.title')    // Returns: Dashboard
 */

return [
    // =========================================================================
    // COMMON - Shared across all pages
    // =========================================================================
    'common' => [
        // Actions
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'edit' => 'Edit',
        'create' => 'Create',
        'update' => 'Update',
        'submit' => 'Submit',
        'confirm' => 'Confirm',
        'close' => 'Close',
        'back' => 'Back',
        'next' => 'Next',
        'previous' => 'Previous',
        'finish' => 'Finish',
        'retry' => 'Retry',
        'refresh' => 'Refresh',
        'search' => 'Search',
        'filter' => 'Filter',
        'sort' => 'Sort',
        'export' => 'Export',
        'import' => 'Import',
        'download' => 'Download',
        'upload' => 'Upload',
        'share' => 'Share',
        'copy' => 'Copy',
        'paste' => 'Paste',
        'print' => 'Print',
        
        // Navigation
        'home' => 'Home',
        'dashboard' => 'Dashboard',
        'login' => 'Login',
        'logout' => 'Logout',
        'register' => 'Sign Up',
        'profile' => 'Profile',
        'settings' => 'Settings',
        'help' => 'Help',
        'support' => 'Support',
        'blog' => 'Blog',
        'faq' => 'FAQ',
        'about' => 'About',
        'contact' => 'Contact',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
        
        // Status
        'active' => 'Active',
        'inactive' => 'Inactive',
        'online' => 'Online',
        'offline' => 'Offline',
        'connected' => 'Connected',
        'disconnected' => 'Disconnected',
        'pending' => 'Pending',
        'loading' => 'Loading',
        'success' => 'Success',
        'error' => 'Error',
        'warning' => 'Warning',
        'info' => 'Info',
        
        // Time
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This Week',
        'this_month' => 'This Month',
        'this_year' => 'This Year',
        'last_week' => 'Last Week',
        'last_month' => 'Last Month',
        'last_year' => 'Last Year',
        
        // Messages
        'confirm_delete' => 'Are you sure you want to delete?',
        'confirm_logout' => 'Are you sure you want to logout?',
        'no_data' => 'No data found',
        'no_results' => 'No results found',
        'required_field' => 'This field is required',
        'invalid_email' => 'Invalid email address',
        'password_mismatch' => 'Passwords do not match',
        
        // Misc
        'yes' => 'Yes',
        'no' => 'No',
        'ok' => 'OK',
        'or' => 'or',
        'and' => 'and',
        'all' => 'All',
        'none' => 'None',
        'other' => 'Other',
        'optional' => 'Optional',
        'required' => 'Required',
    ],
    
    // =========================================================================
    // AUTH - Authentication related
    // =========================================================================
    'auth' => [
        'login_title' => 'Login to Account',
        'login_subtitle' => 'Login to access your trading dashboard',
        'register_title' => 'Create Account',
        'register_subtitle' => 'Quick & Secure Registration',
        'forgot_password' => 'Forgot Password',
        'reset_password' => 'Reset Password',
        'verify_email' => 'Verify Email',
        'email' => 'Email',
        'password' => 'Password',
        'confirm_password' => 'Confirm Password',
        'current_password' => 'Current Password',
        'new_password' => 'New Password',
        'full_name' => 'Full Name',
        'remember_me' => 'Remember me',
        'forgot_password_link' => 'Forgot password?',
        'no_account' => "Don't have an account?",
        'has_account' => 'Already have an account?',
        'create_account' => 'Create Account',
        'login_button' => 'Login to Account',
        'register_button' => 'Sign Up',
        'logout_button' => 'Logout',
        'welcome_back' => 'Welcome Back',
        'welcome_message' => 'Welcome to Velora',
        'password_requirements' => 'Minimum 8 characters, including one letter and one number',
        'email_sent' => 'Email sent',
        'check_email' => 'Check your inbox',
        'resend_email' => 'Resend email',
        'verification_sent' => 'Verification link sent',
        'account_created' => 'Your account has been created successfully',
        'login_success' => 'Login successful',
        'logout_success' => 'Logout successful',
        'password_changed' => 'Password changed',
        'password_reset_sent' => 'Password reset link sent',
    ],
    
    // =========================================================================
    // DASHBOARD - Main dashboard
    // =========================================================================
    'dashboard' => [
        'title' => 'Dashboard',
        'subtitle' => 'Your trading performance summary',
        'welcome' => 'Welcome',
        'overview' => 'Overview',
        'trades' => 'Trades',
        'win_rate' => 'Win Rate',
        'net_pnl' => 'Net P&L',
        'profit_factor' => 'Profit Factor',
        'equity_curve' => 'Equity Curve',
        'recent_trades' => 'Recent Trades',
        'view_all' => 'View All',
        'new_trade' => 'New Trade',
        'refresh' => 'Refresh',
        'loading' => 'Loading...',
        'no_trades' => 'No trades recorded yet',
        'performance_summary' => 'Performance Summary',
        'ai_score' => 'AI Score',
        'risk_level' => 'Risk Level',
        'consistency' => 'Performance Consistency',
        'strategy_performance' => 'Strategy Performance',
        'broker_accounts' => 'Broker Accounts',
        'journal_timeline' => 'Journal Timeline',
        'trading_psychology' => 'Trading Psychology',
    ],
    
    // =========================================================================
    // TRADES - Trade journal
    // =========================================================================
    'trades' => [
        'title' => 'Trade Journal',
        'new_trade' => 'New Trade',
        'edit_trade' => 'Edit Trade',
        'delete_trade' => 'Delete Trade',
        'symbol' => 'Symbol',
        'direction' => 'Direction',
        'buy' => 'Buy',
        'sell' => 'Sell',
        'entry_price' => 'Entry Price',
        'exit_price' => 'Exit Price',
        'volume' => 'Volume',
        'stop_loss' => 'Stop Loss',
        'take_profit' => 'Take Profit',
        'strategy' => 'Strategy',
        'notes' => 'Notes',
        'date' => 'Date',
        'time' => 'Time',
        'open_time' => 'Open Time',
        'close_time' => 'Close Time',
        'pnl' => 'P&L',
        'result' => 'Result',
        'win' => 'Win',
        'loss' => 'Loss',
        'breakeven' => 'Breakeven',
        'r_multiple' => 'R-Multiple',
        'commission' => 'Commission',
        'swap' => 'Swap',
        'net_pnl' => 'Net P&L',
        'emotions' => 'Emotions',
        'setup' => 'Setup',
        'context' => 'Context',
        'lesson' => 'Lesson',
        'mistakes' => 'Mistakes',
        'tags' => 'Tags',
        'search_symbol' => 'Search symbol...',
        'no_trades' => 'No trades recorded yet',
        'trade_saved' => 'Trade saved',
        'trade_deleted' => 'Trade deleted',
        'trade_updated' => 'Trade updated',
    ],
    
    // =========================================================================
    // ACCOUNTS - Trading accounts
    // =========================================================================
    'accounts' => [
        'title' => 'Trading Accounts',
        'connect' => 'Connect Account',
        'disconnect' => 'Disconnect',
        'sync' => 'Sync',
        'account_number' => 'Account Number',
        'investor_password' => 'Investor Password',
        'server' => 'Server',
        'broker' => 'Broker',
        'platform' => 'Platform',
        'balance' => 'Balance',
        'equity' => 'Equity',
        'leverage' => 'Leverage',
        'currency' => 'Currency',
        'status' => 'Status',
        'connected' => 'Connected',
        'disconnected' => 'Disconnected',
        'syncing' => 'Syncing',
        'last_sync' => 'Last Sync',
        'auto_detect' => 'Auto-detect server',
        'connect_success' => 'Connected successfully',
        'connect_error' => 'Connection error',
        'sync_success' => 'Sync successful',
        'sync_error' => 'Sync error',
        'no_accounts' => 'No MetaTrader account connected',
        'add_account' => 'Add Account',
        'mt4' => 'MetaTrader 4',
        'mt5' => 'MetaTrader 5',
    ],
    
    // =========================================================================
    // MARKETS - Market data
    // =========================================================================
    'markets' => [
        'title' => 'Markets',
        'watchlist' => 'Watchlist',
        'forex' => 'Forex',
        'crypto' => 'Crypto',
        'indices' => 'Indices',
        'commodities' => 'Commodities',
        'price' => 'Price',
        'change' => 'Change',
        'high' => 'High',
        'low' => 'Low',
        'volume' => 'Volume',
        'spread' => 'Spread',
        'live' => 'Live',
        'delayed' => 'Delayed',
    ],
    
    // =========================================================================
    // PERFORMANCE - Performance analytics
    // =========================================================================
    'performance' => [
        'title' => 'Performance',
        'overview' => 'Overview',
        'win_rate' => 'Win Rate',
        'profit_factor' => 'Profit Factor',
        'max_drawdown' => 'Max Drawdown',
        'sharpe_ratio' => 'Sharpe Ratio',
        'total_trades' => 'Total Trades',
        'winning_trades' => 'Winning Trades',
        'losing_trades' => 'Losing Trades',
        'avg_win' => 'Average Win',
        'avg_loss' => 'Average Loss',
        'largest_win' => 'Largest Win',
        'largest_loss' => 'Largest Loss',
        'consecutive_wins' => 'Consecutive Wins',
        'consecutive_losses' => 'Consecutive Losses',
        'risk_reward' => 'Risk/Reward Ratio',
        'expectancy' => 'Mathematical Expectancy',
        'daily_pnl' => 'Daily P&L',
        'weekly_pnl' => 'Weekly P&L',
        'monthly_pnl' => 'Monthly P&L',
        'yearly_pnl' => 'Yearly P&L',
    ],
    
    // =========================================================================
    // INTELLIGENCE - AI features
    // =========================================================================
    'intelligence' => [
        'title' => 'Trading Intelligence',
        'ai_coach' => 'AI Coach',
        'ask_question' => 'Ask a Question',
        'insights' => 'Insights',
        'patterns' => 'Patterns',
        'recommendations' => 'Recommendations',
        'behavior_analysis' => 'Behavior Analysis',
        'setup_scoring' => 'Setup Scoring',
        'risk_warnings' => 'Risk Warnings',
        'weekly_report' => 'Weekly Report',
        'monthly_report' => 'Monthly Report',
        'ai_analysis' => 'AI Analysis',
        'confidence' => 'Confidence',
        'pattern_detected' => 'Pattern Detected',
        'overtrading_warning' => 'Overtrading Warning',
        'revenge_trading_warning' => 'Revenge Trading Warning',
    ],
    
    // =========================================================================
    // WALLET - Wallet and financial
    // =========================================================================
    'wallet' => [
        'title' => 'Wallet',
        'balance' => 'Balance',
        'equity' => 'Equity',
        'deposit' => 'Deposit',
        'withdraw' => 'Withdraw',
        'transfer' => 'Transfer',
        'transactions' => 'Transactions',
        'history' => 'History',
        'total_balance' => 'Total Balance',
        'available' => 'Available',
        'in_use' => 'In Use',
        'margin' => 'Margin',
        'free_margin' => 'Free Margin',
    ],
    
    // =========================================================================
    // PROFILE - User profile
    // =========================================================================
    'profile' => [
        'title' => 'Profile',
        'personal_info' => 'Personal Information',
        'security' => 'Security',
        'preferences' => 'Preferences',
        'notifications' => 'Notifications',
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'timezone' => 'Timezone',
        'language' => 'Language',
        'change_password' => 'Change Password',
        'two_factor' => 'Two-Factor Authentication',
        'sessions' => 'Sessions',
        'devices' => 'Devices',
        'connected_accounts' => 'Connected Accounts',
        'delete_account' => 'Delete Account',
        'profile_updated' => 'Profile updated',
        'password_changed' => 'Password changed',
    ],
    
    // =========================================================================
    // ADMIN - Admin panel
    // =========================================================================
    'admin' => [
        'title' => 'Admin Panel',
        'users' => 'Users',
        'user_management' => 'User Management',
        'roles' => 'Roles',
        'permissions' => 'Permissions',
        'logs' => 'Logs',
        'settings' => 'System Settings',
        'statistics' => 'Statistics',
        'active_users' => 'Active Users',
        'total_users' => 'Total Users',
        'new_users' => 'New Users',
        'search_users' => 'Search Users',
        'user_details' => 'User Details',
        'ban_user' => 'Ban User',
        'unban_user' => 'Unban User',
        'change_role' => 'Change Role',
    ],
    
    // =========================================================================
    // SUPPORT - Help and support
    // =========================================================================
    'support' => [
        'title' => 'Support',
        'help_center' => 'Help Center',
        'contact_us' => 'Contact Us',
        'faq' => 'FAQ',
        'documentation' => 'Documentation',
        'ticket' => 'Ticket',
        'new_ticket' => 'New Ticket',
        'my_tickets' => 'My Tickets',
        'subject' => 'Subject',
        'message' => 'Message',
        'priority' => 'Priority',
        'status' => 'Status',
        'open' => 'Open',
        'closed' => 'Closed',
        'pending' => 'Pending',
        'answered' => 'Answered',
        'submit_ticket' => 'Submit Ticket',
        'ticket_submitted' => 'Ticket submitted',
    ],
    
    // =========================================================================
    // NEWS - News and events
    // =========================================================================
    'news' => [
        'title' => 'News',
        'latest_news' => 'Latest News',
        'breaking' => 'Breaking',
        'analysis' => 'Analysis',
        'calendar' => 'Economic Calendar',
        'events' => 'Events',
        'high_impact' => 'High Impact',
        'medium_impact' => 'Medium Impact',
        'low_impact' => 'Low Impact',
        'actual' => 'Actual',
        'forecast' => 'Forecast',
        'previous' => 'Previous',
        'time' => 'Time',
        'currency' => 'Currency',
        'event' => 'Event',
    ],
    
    // =========================================================================
    // BLOG - Blog and articles
    // =========================================================================
    'blog' => [
        'title' => 'Blog',
        'articles' => 'Articles',
        'categories' => 'Categories',
        'tags' => 'Tags',
        'read_more' => 'Read More',
        'share' => 'Share',
        'related_articles' => 'Related Articles',
        'latest_articles' => 'Latest Articles',
        'search' => 'Search Articles',
        'no_articles' => 'No articles found',
        'min_read' => 'min read',
        'author' => 'Author',
        'published' => 'Published',
        'updated' => 'Last Updated',
    ],
    
    // =========================================================================
    // ERRORS - Error messages
    // =========================================================================
    'errors' => [
        'not_found' => 'Page Not Found',
        'unauthorized' => 'Unauthorized',
        'forbidden' => 'Forbidden',
        'server_error' => 'Server Error',
        'bad_request' => 'Bad Request',
        'timeout' => 'Connection Timeout',
        'network_error' => 'Network Error',
        'validation_error' => 'Validation Error',
        'rate_limit' => 'Too Many Requests',
        'try_again' => 'Please try again',
        'contact_support' => 'Contact Support',
        'go_home' => 'Go to Home',
        'go_back' => 'Go Back',
    ],
    
    // =========================================================================
    // NOTIFICATIONS - System notifications
    // =========================================================================
    'notifications' => [
        'trade_saved' => 'Trade saved',
        'trade_deleted' => 'Trade deleted',
        'account_connected' => 'Account connected',
        'account_disconnected' => 'Account disconnected',
        'sync_complete' => 'Sync complete',
        'sync_failed' => 'Sync failed',
        'password_changed' => 'Password changed',
        'profile_updated' => 'Profile updated',
        'settings_saved' => 'Settings saved',
        'new_login' => 'New login to your account',
        'suspicious_activity' => 'Suspicious activity detected',
        'maintenance' => 'System maintenance',
        'update_available' => 'Update available',
    ],
    
    // =========================================================================
    // FORMS - Form labels and placeholders
    // =========================================================================
    'forms' => [
        'required_field' => 'Required field',
        'optional_field' => 'Optional field',
        'select_option' => 'Select...',
        'enter_value' => 'Enter value...',
        'search' => 'Search...',
        'no_results' => 'No results found',
        'loading' => 'Loading...',
        'submitting' => 'Submitting...',
        'validation_required' => 'This field is required',
        'validation_email' => 'Enter a valid email',
        'validation_min' => 'Minimum :min characters',
        'validation_max' => 'Maximum :max characters',
        'validation_pattern' => 'Invalid format',
        'validation_match' => 'Values do not match',
        'validation_number' => 'Enter a valid number',
        'validation_positive' => 'Enter a positive number',
        'validation_date' => 'Enter a valid date',
    ],
    
    // =========================================================================
    // UNITS - Measurement units
    // =========================================================================
    'units' => [
        'pips' => 'pips',
        'points' => 'points',
        'lots' => 'lots',
        'micro_lots' => 'micro lots',
        'mini_lots' => 'mini lots',
        'percent' => 'percent',
        'usd' => 'USD',
        'eur' => 'EUR',
        'gbp' => 'GBP',
        'jpy' => 'JPY',
        'seconds' => 'seconds',
        'minutes' => 'minutes',
        'hours' => 'hours',
        'days' => 'days',
        'weeks' => 'weeks',
        'months' => 'months',
        'years' => 'years',
    ],
    
    // =========================================================================
    // AI - AI-related content
    // =========================================================================
    'ai' => [
        'coach' => 'AI Coach',
        'analysis' => 'AI Analysis',
        'insights' => 'AI Insights',
        'recommendations' => 'AI Recommendations',
        'patterns' => 'Detected Patterns',
        'behavior' => 'Behavior Analysis',
        'overtrading' => 'Overtrading',
        'revenge_trading' => 'Revenge Trading',
        'emotional_trading' => 'Emotional Trading',
        'risk_management' => 'Risk Management',
        'consistency' => 'Consistency',
        'discipline' => 'Discipline',
        'patience' => 'Patience',
        'setup_quality' => 'Setup Quality',
        'entry_timing' => 'Entry Timing',
        'exit_strategy' => 'Exit Strategy',
        'position_sizing' => 'Position Sizing',
        'daily_report' => 'Daily Report',
        'weekly_report' => 'Weekly Report',
        'monthly_report' => 'Monthly Report',
    ],
];
