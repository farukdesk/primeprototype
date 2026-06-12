import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../models/notice_model.dart';
import '../../services/api_service.dart';
import '../../services/auth_service.dart';
import '../../theme/app_theme.dart';

/// Dashboard tab: welcome card, stats, and recent notices.
class DashboardTab extends StatefulWidget {
  const DashboardTab({super.key});
  @override
  State<DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<DashboardTab> {
  List<NoticeModel> _recentNotices = [];
  bool _loadingNotices = true;

  @override
  void initState() {
    super.initState();
    _loadRecentNotices();
  }

  Future<void> _loadRecentNotices() async {
    try {
      final data = await ApiService.getNotices(
        type: 'university',
        page: 1,
        onUnauthorized: () => context.read<AuthService>().logout(),
      );
      if (data['ok'] == true && mounted) {
        final raw = (data['notices'] as List?) ?? [];
        setState(() {
          _recentNotices = raw
              .take(3)
              .map((n) => NoticeModel.fromJson(n as Map<String, dynamic>))
              .toList();
          _loadingNotices = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loadingNotices = false);
    }
  }

  Future<void> _refresh() async {
    setState(() => _loadingNotices = true);
    await context.read<AuthService>().refreshUser();
    await _loadRecentNotices();
  }

  @override
  Widget build(BuildContext context) {
    final student = context.watch<AuthService>().currentStudent;

    return RefreshIndicator(
      onRefresh: _refresh,
      color: AppTheme.primary,
      child: CustomScrollView(
        slivers: [
          // ── App bar ────────────────────────────────────────────────────
          SliverAppBar(
            expandedHeight: 180,
            pinned: true,
            stretch: true,
            backgroundColor: AppTheme.primary,
            flexibleSpace: FlexibleSpaceBar(
              collapseMode: CollapseMode.parallax,
              background: _buildHeaderBg(student?.fullName ?? ''),
            ),
            title: const Text('Home'),
            actions: [
              IconButton(
                icon: const Icon(Icons.settings_outlined),
                tooltip: 'Settings',
                onPressed: () => context.push('/settings'),
              ),
            ],
          ),

          SliverPadding(
            padding: const EdgeInsets.all(16),
            sliver: SliverList(
              delegate: SliverChildListDelegate([
                // ── Stats row ──────────────────────────────────────────
                if (student != null) ...[
                  _buildStatsRow(student.noticesUniversity,
                      student.outstandingBalance),
                  const SizedBox(height: 20),
                ],

                // ── Recent notices ─────────────────────────────────────
                _SectionHeader(
                  title: 'Recent Notices',
                  actionLabel: 'View all',
                  onAction: () {
                    // Navigate parent to notices tab
                    // Handled by HomeScreen via IndexedStack
                  },
                ),
                const SizedBox(height: 12),

                if (_loadingNotices)
                  const Center(child: CircularProgressIndicator())
                else if (_recentNotices.isEmpty)
                  _EmptyState(
                    icon: Icons.notifications_none_rounded,
                    message: 'No recent notices.',
                  )
                else
                  ..._recentNotices.map((n) => Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: _NoticeCard(
                          notice: n,
                          onTap: () => context.push(
                            '/notice-detail',
                            extra: {
                              'id':   n.id,
                              'type': n.type,
                              'notice': n,
                            },
                          ),
                        ),
                      )),

                const SizedBox(height: 80),
              ]),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildHeaderBg(String name) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [AppTheme.primaryDark, AppTheme.primary],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      padding: const EdgeInsets.fromLTRB(20, 80, 20, 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          Text(
            'Welcome back, 👋',
            style: TextStyle(color: Colors.white.withOpacity(0.75), fontSize: 13),
          ),
          const SizedBox(height: 4),
          Text(
            name.isNotEmpty ? name : 'Student',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 22,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStatsRow(int noticeCount, double? outstanding) {
    return Row(
      children: [
        Expanded(
          child: _StatCard(
            icon:  Icons.notifications_rounded,
            color: AppTheme.info,
            label: 'Notices',
            value: '$noticeCount',
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: _StatCard(
            icon:  Icons.account_balance_wallet_rounded,
            color: outstanding != null && outstanding > 0
                ? AppTheme.error
                : AppTheme.success,
            label: 'Outstanding',
            value: outstanding != null
                ? '৳${outstanding.toStringAsFixed(0)}'
                : '—',
          ),
        ),
      ],
    );
  }
}

// ── Sub-widgets ───────────────────────────────────────────────────────────────

class _StatCard extends StatelessWidget {
  final IconData icon;
  final Color    color;
  final String   label;
  final String   value;
  const _StatCard({required this.icon, required this.color, required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 8, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: color.withOpacity(0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: color, size: 20),
          ),
          const SizedBox(height: 12),
          Text(
            value,
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: color),
          ),
          const SizedBox(height: 2),
          Text(label, style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
        ],
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  final String  title;
  final String? actionLabel;
  final VoidCallback? onAction;
  const _SectionHeader({required this.title, this.actionLabel, this.onAction});

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          title,
          style: const TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.w700,
            color: AppTheme.textPrimary,
          ),
        ),
        if (actionLabel != null)
          TextButton(
            onPressed: onAction,
            style: TextButton.styleFrom(
              padding: EdgeInsets.zero,
              minimumSize: const Size(0, 0),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            ),
            child: Text(actionLabel!, style: const TextStyle(fontSize: 12)),
          ),
      ],
    );
  }
}

class _NoticeCard extends StatelessWidget {
  final NoticeModel notice;
  final VoidCallback onTap;
  const _NoticeCard({required this.notice, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final color = notice.isDepartment ? AppTheme.success : AppTheme.primary;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border(left: BorderSide(color: color, width: 4)),
          boxShadow: [
            BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 6, offset: const Offset(0, 2)),
          ],
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                        decoration: BoxDecoration(
                          color: color.withOpacity(0.1),
                          borderRadius: BorderRadius.circular(20),
                        ),
                        child: Text(
                          notice.isDepartment ? (notice.deptName ?? 'Department') : 'University',
                          style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w600),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        notice.date,
                        style: const TextStyle(color: AppTheme.textSecondary, fontSize: 10),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    notice.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: AppTheme.textPrimary,
                    ),
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_right_rounded, color: AppTheme.textSecondary, size: 20),
          ],
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  final IconData icon;
  final String   message;
  const _EmptyState({required this.icon, required this.message});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        children: [
          const SizedBox(height: 32),
          Icon(icon, size: 48, color: AppTheme.textSecondary.withOpacity(0.4)),
          const SizedBox(height: 12),
          Text(
            message,
            style: TextStyle(color: AppTheme.textSecondary, fontSize: 14),
          ),
        ],
      ),
    );
  }
}
