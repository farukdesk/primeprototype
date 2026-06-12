import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../services/auth_service.dart';
import '../theme/app_theme.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});
  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  String _appVersion = '';

  @override
  void initState() {
    super.initState();
    _loadVersion();
  }

  Future<void> _loadVersion() async {
    final info = await PackageInfo.fromPlatform();
    if (mounted) setState(() => _appVersion = info.version);
  }

  Future<void> _logout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sign Out'),
        content: const Text(
            'Are you sure you want to sign out of the student portal?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            style:
                ElevatedButton.styleFrom(backgroundColor: AppTheme.error),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Sign Out'),
          ),
        ],
      ),
    );
    if (confirmed == true && mounted) {
      await context.read<AuthService>().logout();
      if (mounted) context.go('/login');
    }
  }

  @override
  Widget build(BuildContext context) {
    final student = context.watch<AuthService>().currentStudent;

    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(title: const Text('Settings')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // ── Account section ───────────────────────────────────────────
          if (student != null) ...[
            _SectionLabel('Account'),
            _SettingsCard(
              children: [
                _ListTileItem(
                  icon: Icons.person_rounded,
                  iconColor: AppTheme.primary,
                  title: student.fullName,
                  subtitle: student.studentId,
                ),
                const Divider(height: 1, indent: 56),
                _ListTileItem(
                  icon: Icons.domain_rounded,
                  iconColor: AppTheme.primary,
                  title: student.deptName,
                  subtitle: student.programName ?? '',
                ),
              ],
            ),
            const SizedBox(height: 20),
          ],

          // ── Notifications section ────────────────────────────────────
          _SectionLabel('Notifications'),
          _SettingsCard(
            children: [
              _ListTileItem(
                icon: Icons.notifications_active_rounded,
                iconColor: AppTheme.info,
                title: 'University Notices',
                subtitle: 'Receive alerts for new university notices',
                trailing: _StatusChip(label: 'Active', color: AppTheme.success),
              ),
              const Divider(height: 1, indent: 56),
              _ListTileItem(
                icon: Icons.campaign_rounded,
                iconColor: AppTheme.warning,
                title: 'Department Notices',
                subtitle: 'Receive alerts from your department',
                trailing: _StatusChip(label: 'Active', color: AppTheme.success),
              ),
            ],
          ),
          const SizedBox(height: 20),

          // ── Support section ───────────────────────────────────────────
          _SectionLabel('Help & Support'),
          _SettingsCard(
            children: [
              _ListTileItem(
                icon: Icons.privacy_tip_outlined,
                iconColor: AppTheme.info,
                title: 'Privacy Policy',
                onTap: () => launchUrl(
                    Uri.parse(
                        'https://primeuniversity.ac.bd/privacy-policy.php'),
                    mode: LaunchMode.externalApplication),
              ),
              const Divider(height: 1, indent: 56),
              _ListTileItem(
                icon: Icons.info_outline_rounded,
                iconColor: AppTheme.textSecondary,
                title: 'About',
                subtitle: 'PU Student Portal v$_appVersion',
              ),
            ],
          ),
          const SizedBox(height: 20),

          // ── Sign out ──────────────────────────────────────────────────
          SizedBox(
            height: 50,
            child: OutlinedButton.icon(
              icon: const Icon(Icons.logout_rounded, color: AppTheme.error),
              label: const Text('Sign Out',
                  style: TextStyle(color: AppTheme.error)),
              style: OutlinedButton.styleFrom(
                side: const BorderSide(color: AppTheme.error),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              onPressed: _logout,
            ),
          ),
          const SizedBox(height: 40),
        ],
      ),
    );
  }
}

// ── Helper widgets ────────────────────────────────────────────────────────────

class _SectionLabel extends StatelessWidget {
  final String text;
  const _SectionLabel(this.text);
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(left: 4, bottom: 8),
      child: Text(
        text.toUpperCase(),
        style: const TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: AppTheme.textSecondary,
          letterSpacing: 0.8,
        ),
      ),
    );
  }
}

class _SettingsCard extends StatelessWidget {
  final List<Widget> children;
  const _SettingsCard({required this.children});
  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(color: Colors.black.withOpacity(0.04),
              blurRadius: 8, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(children: children),
    );
  }
}

class _ListTileItem extends StatelessWidget {
  final IconData icon;
  final Color    iconColor;
  final String   title;
  final String?  subtitle;
  final Widget?  trailing;
  final VoidCallback? onTap;
  const _ListTileItem({
    required this.icon,
    required this.iconColor,
    required this.title,
    this.subtitle,
    this.trailing,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 2),
      leading: Container(
        width: 36,
        height: 36,
        decoration: BoxDecoration(
          color: iconColor.withOpacity(0.1),
          borderRadius: BorderRadius.circular(9),
        ),
        child: Icon(icon, color: iconColor, size: 18),
      ),
      title: Text(
        title,
        style: const TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w500,
            color: AppTheme.textPrimary),
      ),
      subtitle: subtitle != null && subtitle!.isNotEmpty
          ? Text(subtitle!,
              style: const TextStyle(
                  fontSize: 12, color: AppTheme.textSecondary))
          : null,
      trailing: trailing ?? (onTap != null
          ? const Icon(Icons.chevron_right_rounded,
              color: AppTheme.textSecondary, size: 20)
          : null),
      onTap: onTap,
    );
  }
}

class _StatusChip extends StatelessWidget {
  final String label;
  final Color  color;
  const _StatusChip({required this.label, required this.color});
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withOpacity(0.3)),
      ),
      child: Text(
        label,
        style: TextStyle(
            color: color, fontSize: 11, fontWeight: FontWeight.w600),
      ),
    );
  }
}
