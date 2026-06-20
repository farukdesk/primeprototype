import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../models/student_model.dart';
import '../../services/auth_service.dart';
import '../../theme/app_theme.dart';

class ProfileTab extends StatelessWidget {
  const ProfileTab({super.key});

  @override
  Widget build(BuildContext context) {
    final student = context.watch<AuthService>().currentStudent;
    if (student == null) return const SizedBox.shrink();

    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(
        title: const Text('Profile'),
        actions: [
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            tooltip: 'Settings',
            onPressed: () => context.push('/settings'),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // ── Avatar + name card ────────────────────────────────────────
          _buildProfileHeader(student),
          const SizedBox(height: 16),

          // ── Academic info ─────────────────────────────────────────────
          _InfoCard(
            title: 'Academic Information',
            icon: Icons.school_rounded,
            items: [
              _InfoItem('Student ID',  student.studentId),
              _InfoItem('Status',      student.status),
              _InfoItem('Department',  student.deptName),
              if (student.programName != null)
                _InfoItem('Program',   student.programName!),
              if (student.batchName != null)
                _InfoItem('Batch',     student.batchName!),
            ],
          ),
          const SizedBox(height: 12),

          // ── Contact info ──────────────────────────────────────────────
          _InfoCard(
            title: 'Contact Information',
            icon: Icons.contact_mail_rounded,
            items: [
              _InfoItem('Email',       student.email),
              if (student.phone != null && student.phone!.isNotEmpty)
                _InfoItem('Phone',     student.phone!),
            ],
          ),
          const SizedBox(height: 80),
        ],
      ),
    );
  }

  Widget _buildProfileHeader(StudentModel student) {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AppTheme.primaryDark, AppTheme.primary],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        children: [
          // Avatar
          Container(
            width: 72,
            height: 72,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: AppTheme.accent,
              border: Border.all(color: Colors.white, width: 2),
            ),
            child: Center(
              child: Text(
                student.initials,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 26,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  student.fullName,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  student.studentId,
                  style: const TextStyle(
                    color: AppTheme.accent,
                    fontSize: 13,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  student.deptName,
                  style: TextStyle(
                    color: Colors.white.withOpacity(0.75),
                    fontSize: 12,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _InfoCard extends StatelessWidget {
  final String        title;
  final IconData      icon;
  final List<_InfoItem> items;
  const _InfoCard({required this.title, required this.icon, required this.items});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(color: Colors.black.withOpacity(0.04),
              blurRadius: 8, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: Row(
              children: [
                Icon(icon, size: 18, color: AppTheme.primary),
                const SizedBox(width: 8),
                Text(
                  title,
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppTheme.primary,
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          ...items.map((item) => _buildRow(item)),
        ],
      ),
    );
  }

  Widget _buildRow(_InfoItem item) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(
              item.label,
              style: const TextStyle(
                fontSize: 12,
                color: AppTheme.textSecondary,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
          Expanded(
            child: Text(
              item.value.isNotEmpty ? item.value : '—',
              style: const TextStyle(
                fontSize: 13,
                color: AppTheme.textPrimary,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _InfoItem {
  final String label, value;
  const _InfoItem(this.label, this.value);
}
