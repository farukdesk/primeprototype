import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../models/user_model.dart';
import '../services/auth_service.dart';
import '../services/connectivity_service.dart';

/// Main dashboard screen shown after a successful login.
/// Auto-refreshes user data every 60 seconds.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  Timer? _refreshTimer;
  bool   _refreshing = false;

  @override
  void initState() {
    super.initState();
    _startAutoRefresh();
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  void _startAutoRefresh() {
    _refreshTimer = Timer.periodic(const Duration(seconds: 60), (_) {
      _refresh(silent: true);
    });
  }

  Future<void> _refresh({bool silent = false}) async {
    if (!silent) setState(() => _refreshing = true);
    await context.read<AuthService>().refreshUser();
    if (mounted && !silent) setState(() => _refreshing = false);
  }

  Future<void> _logout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sign Out'),
        content: const Text('Are you sure you want to sign out?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            child: const Text('Sign Out'),
          ),
        ],
      ),
    );
    if (confirm == true && mounted) {
      await context.read<AuthService>().logout();
      if (mounted) context.go('/login');
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth        = context.watch<AuthService>();
    final connectivity = context.watch<ConnectivityService>();
    final user        = auth.currentUser;

    return Scaffold(
      backgroundColor: const Color(0xFFF3F4F8),
      appBar: _buildAppBar(user),
      body: Column(
        children: [
          // Offline banner
          if (!connectivity.isOnline) _OfflineBanner(),

          Expanded(
            child: RefreshIndicator(
              onRefresh: () => _refresh(),
              child: _refreshing
                  ? const Center(child: CircularProgressIndicator())
                  : _buildBody(user, connectivity),
            ),
          ),
        ],
      ),
    );
  }

  AppBar _buildAppBar(UserModel? user) {
    return AppBar(
      backgroundColor: const Color(0xFF1A1F36),
      title: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.school_rounded, size: 20),
          const SizedBox(width: 8),
          const Text('PUMIS Admin'),
        ],
      ),
      actions: [
        // Notification bell with unread count
        if (user != null)
          Stack(
            alignment: Alignment.center,
            children: [
              IconButton(
                icon: const Icon(Icons.notifications_none_rounded),
                onPressed: () {},
                tooltip: 'Notifications',
              ),
              if ((user.unread['open_tickets'] ?? 0) > 0)
                Positioned(
                  top: 8,
                  right: 8,
                  child: Container(
                    width: 16,
                    height: 16,
                    decoration: const BoxDecoration(
                      color: Color(0xFFEF4444),
                      shape: BoxShape.circle,
                    ),
                    child: Center(
                      child: Text(
                        '${user.unread['open_tickets']}',
                        style: const TextStyle(fontSize: 10, color: Colors.white),
                      ),
                    ),
                  ),
                ),
            ],
          ),

        // Avatar / logout
        PopupMenuButton<String>(
          icon: CircleAvatar(
            backgroundColor: const Color(0xFF4F8EF7),
            radius: 16,
            child: Text(
              user?.fullName.isNotEmpty == true
                  ? user!.fullName[0].toUpperCase()
                  : '?',
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
            ),
          ),
          onSelected: (v) {
            if (v == 'logout') _logout();
          },
          itemBuilder: (_) => [
            PopupMenuItem(
              enabled: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    user?.fullName ?? '',
                    style: const TextStyle(
                      fontWeight: FontWeight.w600,
                      color: Color(0xFF1A1F36),
                    ),
                  ),
                  Text(
                    user?.group ?? '',
                    style: const TextStyle(fontSize: 12, color: Color(0xFF9CA3AF)),
                  ),
                ],
              ),
            ),
            const PopupMenuDivider(),
            const PopupMenuItem(
              value: 'logout',
              child: Row(
                children: [
                  Icon(Icons.logout_rounded, color: Color(0xFFEF4444), size: 18),
                  SizedBox(width: 10),
                  Text('Sign Out', style: TextStyle(color: Color(0xFFEF4444))),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(width: 4),
      ],
    );
  }

  Widget _buildBody(UserModel? user, ConnectivityService connectivity) {
    if (user == null) {
      return const Center(child: CircularProgressIndicator());
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Welcome card
        _WelcomeCard(user: user),
        const SizedBox(height: 16),

        // Stat cards
        _buildStatCards(user),
        const SizedBox(height: 20),

        // Module grid (quick links)
        _buildModuleGrid(user),
      ],
    );
  }

  Widget _buildStatCards(UserModel user) {
    final openTickets       = user.unread['open_tickets']       ?? 0;
    final pendingBroadcasts = user.unread['pending_broadcasts'] ?? 0;

    return Row(
      children: [
        Expanded(
          child: _StatCard(
            icon: Icons.confirmation_number_rounded,
            color: const Color(0xFF4F8EF7),
            label: 'Open Tickets',
            value: '$openTickets',
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: _StatCard(
            icon: Icons.campaign_rounded,
            color: const Color(0xFFF59E0B),
            label: 'Pending Broadcasts',
            value: '$pendingBroadcasts',
          ),
        ),
      ],
    );
  }

  Widget _buildModuleGrid(UserModel user) {
    final modules = _visibleModules(user);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Quick Access',
          style: TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w700,
            color: Color(0xFF1A1F36),
          ),
        ),
        const SizedBox(height: 12),
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 3,
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            childAspectRatio: 0.9,
          ),
          itemCount: modules.length,
          itemBuilder: (_, i) => _ModuleTile(
            icon: modules[i].icon,
            label: modules[i].label,
            color: modules[i].color,
          ),
        ),
      ],
    );
  }

  List<_ModuleInfo> _visibleModules(UserModel user) {
    final all = [
      _ModuleInfo('students',         'Students',      Icons.people_rounded,           const Color(0xFF4F8EF7)),
      _ModuleInfo('student-accounts', 'Accounts',      Icons.account_balance_rounded,  const Color(0xFF10B981)),
      _ModuleInfo('admissions',        'Admissions',    Icons.how_to_reg_rounded,       const Color(0xFF8B5CF6)),
      _ModuleInfo('accounting',        'Accounting',    Icons.receipt_long_rounded,     const Color(0xFFF59E0B)),
      _ModuleInfo('support-tickets',   'IT Support',    Icons.support_agent_rounded,    const Color(0xFFEF4444)),
      _ModuleInfo('broadcast',         'Broadcast',     Icons.campaign_rounded,         const Color(0xFF06B6D4)),
      _ModuleInfo('results',           'Results',       Icons.grading_rounded,          const Color(0xFF6366F1)),
      _ModuleInfo('departments',       'Departments',   Icons.domain_rounded,           const Color(0xFFEC4899)),
    ];
    return all.where((m) => user.hasPermission(m.slug)).toList();
  }
}

// ── Data class ────────────────────────────────────────────────────────────────

class _ModuleInfo {
  final String slug;
  final String label;
  final IconData icon;
  final Color color;
  const _ModuleInfo(this.slug, this.label, this.icon, this.color);
}

// ── Sub-widgets ───────────────────────────────────────────────────────────────

class _WelcomeCard extends StatelessWidget {
  final UserModel user;
  const _WelcomeCard({required this.user});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF1A1F36), Color(0xFF2D3561)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          CircleAvatar(
            backgroundColor: const Color(0xFF4F8EF7),
            radius: 28,
            child: Text(
              user.fullName.isNotEmpty ? user.fullName[0].toUpperCase() : '?',
              style: const TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.w700,
                color: Colors.white,
              ),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Welcome back,',
                  style: TextStyle(color: Colors.white.withOpacity(0.6), fontSize: 12),
                ),
                Text(
                  user.fullName,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.w600,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                Text(
                  user.group,
                  style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 12),
                ),
              ],
            ),
          ),
          if (user.isSuper)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: const Color(0xFF4F8EF7),
                borderRadius: BorderRadius.circular(20),
              ),
              child: const Text(
                'Super Admin',
                style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w600),
              ),
            ),
        ],
      ),
    );
  }
}

class _StatCard extends StatelessWidget {
  final IconData icon;
  final Color    color;
  final String   label;
  final String   value;
  const _StatCard({
    required this.icon,
    required this.color,
    required this.label,
    required this.value,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.05),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: color, size: 26),
          const SizedBox(height: 10),
          Text(
            value,
            style: TextStyle(
              fontSize: 26,
              fontWeight: FontWeight.w700,
              color: color,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            label,
            style: const TextStyle(fontSize: 12, color: Color(0xFF9CA3AF)),
          ),
        ],
      ),
    );
  }
}

class _ModuleTile extends StatelessWidget {
  final IconData icon;
  final String   label;
  final Color    color;
  const _ModuleTile({required this.icon, required this.label, required this.color});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () {},
      borderRadius: BorderRadius.circular(14),
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.04),
              blurRadius: 6,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: color.withOpacity(0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(icon, color: color, size: 22),
            ),
            const SizedBox(height: 8),
            Text(
              label,
              style: const TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w600,
                color: Color(0xFF374151),
              ),
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}

class _OfflineBanner extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      color: const Color(0xFFFF6B6B),
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          const Icon(Icons.wifi_off_rounded, color: Colors.white, size: 16),
          const SizedBox(width: 8),
          const Text(
            'You are offline — data may be out of date.',
            style: TextStyle(color: Colors.white, fontSize: 12),
          ),
        ],
      ),
    );
  }
}
