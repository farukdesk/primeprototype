import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../services/auth_service.dart';
import '../services/connectivity_service.dart';
import '../services/fcm_service.dart';
import '../theme/app_theme.dart';
import 'tabs/dashboard_tab.dart';
import 'tabs/notices_tab.dart';
import 'tabs/finances_tab.dart';
import 'tabs/profile_tab.dart';

/// Main screen with bottom navigation bar.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int   _tabIndex = 0;
  Timer? _refreshTimer;

  static const _tabs = [
    _TabInfo(Icons.home_outlined,       Icons.home_rounded,        'Home'),
    _TabInfo(Icons.notifications_none,  Icons.notifications_rounded,'Notices'),
    _TabInfo(Icons.account_balance_wallet_outlined, Icons.account_balance_wallet_rounded, 'Finances'),
    _TabInfo(Icons.person_outline,      Icons.person_rounded,       'Profile'),
  ];

  @override
  void initState() {
    super.initState();
    _registerFcm();
    // Refresh user data every 5 minutes
    _refreshTimer = Timer.periodic(const Duration(minutes: 5), (_) {
      context.read<AuthService>().refreshUser();
    });
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  void _registerFcm() async {
    await FcmService.init();
    if (!mounted) return;
    await FcmService.registerToken(
      onUnauthorized: () {
        context.read<AuthService>().logout();
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final online = context.watch<ConnectivityService>().isOnline;

    return Scaffold(
      backgroundColor: AppTheme.background,
      body: Column(
        children: [
          if (!online) _OfflineBanner(),
          Expanded(
            child: IndexedStack(
              index: _tabIndex,
              children: const [
                DashboardTab(),
                NoticesTab(),
                FinancesTab(),
                ProfileTab(),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: _buildBottomBar(),
    );
  }

  Widget _buildBottomBar() {
    return BottomNavigationBar(
      currentIndex: _tabIndex,
      onTap: (i) => setState(() => _tabIndex = i),
      items: _tabs.map((t) {
        return BottomNavigationBarItem(
          icon:       Icon(t.icon),
          activeIcon: Icon(t.activeIcon),
          label:      t.label,
        );
      }).toList(),
    );
  }
}

class _TabInfo {
  final IconData icon;
  final IconData activeIcon;
  final String   label;
  const _TabInfo(this.icon, this.activeIcon, this.label);
}

class _OfflineBanner extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppTheme.error,
      child: SafeArea(
        bottom: false,
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: const Row(
            children: [
              Icon(Icons.wifi_off_rounded, color: Colors.white, size: 16),
              SizedBox(width: 8),
              Text(
                'You are offline – data may be outdated.',
                style: TextStyle(color: Colors.white, fontSize: 12),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
