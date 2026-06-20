import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../models/notice_model.dart';
import '../theme/app_theme.dart';

/// Full notice detail screen.
class NoticeDetailScreen extends StatelessWidget {
  final Map<String, dynamic> notice;
  const NoticeDetailScreen({super.key, required this.notice});

  @override
  Widget build(BuildContext context) {
    final n = notice['notice'] as NoticeModel;
    final accent = n.isDepartment ? AppTheme.success : AppTheme.primary;

    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(
        title: Text(
          n.isDepartment ? '${n.deptName ?? "Dept"} Notice' : 'University Notice',
        ),
        backgroundColor: accent,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ── Type badge + date ─────────────────────────────────────
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: accent.withOpacity(0.1),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: accent.withOpacity(0.3)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        n.isDepartment
                            ? Icons.domain_rounded
                            : Icons.account_balance_rounded,
                        size: 12,
                        color: accent,
                      ),
                      const SizedBox(width: 4),
                      Text(
                        n.isDepartment
                            ? (n.deptName ?? 'Department')
                            : 'University',
                        style: TextStyle(
                            color: accent,
                            fontSize: 11,
                            fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                const Icon(Icons.calendar_today_rounded,
                    size: 12, color: AppTheme.textSecondary),
                const SizedBox(width: 4),
                Text(
                  n.date,
                  style: const TextStyle(
                      fontSize: 12, color: AppTheme.textSecondary),
                ),
              ],
            ),
            const SizedBox(height: 16),

            // ── Title ─────────────────────────────────────────────────
            Text(
              n.title,
              style: const TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: AppTheme.textPrimary,
                height: 1.3,
              ),
            ),
            const SizedBox(height: 20),
            const Divider(),
            const SizedBox(height: 16),

            // ── Content ───────────────────────────────────────────────
            if (n.content != null && n.content!.trim().isNotEmpty)
              Text(
                n.content!.replaceAll(RegExp(r'<[^>]*>'), '').trim(),
                style: const TextStyle(
                  fontSize: 15,
                  color: AppTheme.textPrimary,
                  height: 1.7,
                ),
              )
            else
              Text(
                'No content provided.',
                style: TextStyle(
                    color: AppTheme.textSecondary.withOpacity(0.6),
                    fontStyle: FontStyle.italic),
              ),

            // ── Attachment ────────────────────────────────────────────
            if (n.hasAttachment) ...[
              const SizedBox(height: 24),
              const Divider(),
              const SizedBox(height: 16),
              const Text(
                'Attachment',
                style: TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 14,
                    color: AppTheme.textPrimary),
              ),
              const SizedBox(height: 10),
              InkWell(
                onTap: () => _openAttachment(n.attachmentUrl!),
                borderRadius: BorderRadius.circular(12),
                child: Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: accent.withOpacity(0.07),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: accent.withOpacity(0.2)),
                  ),
                  child: Row(
                    children: [
                      Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(
                          color: accent.withOpacity(0.15),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Icon(Icons.attach_file_rounded,
                            color: accent, size: 20),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              n.attachmentName ?? 'Download Attachment',
                              style: TextStyle(
                                  fontWeight: FontWeight.w600,
                                  fontSize: 13,
                                  color: accent),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            if (n.attachmentSizeKb != null)
                              Text(
                                '${n.attachmentSizeKb} KB',
                                style: const TextStyle(
                                    fontSize: 11,
                                    color: AppTheme.textSecondary),
                              ),
                          ],
                        ),
                      ),
                      Icon(Icons.download_rounded, color: accent, size: 20),
                    ],
                  ),
                ),
              ),
            ],

            const SizedBox(height: 40),
          ],
        ),
      ),
    );
  }

  Future<void> _openAttachment(String url) async {
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }
}
