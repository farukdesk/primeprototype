import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/course_offer_model.dart';
import '../../services/api_service.dart';
import '../../services/auth_service.dart';
import '../../theme/app_theme.dart';

/// Shows the course offers targeted at the student's batch and lets them
/// self-register for (or drop) subjects while registration is open.
class CoursesTab extends StatefulWidget {
  const CoursesTab({super.key});
  @override
  State<CoursesTab> createState() => _CoursesTabState();
}

class _CoursesTabState extends State<CoursesTab> {
  List<CourseOffer> _offers = [];
  bool    _loading = true;
  String? _error;
  String? _message;
  final Set<int> _busy = {}; // offerSubjectIds with an in-flight request

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; _message = null; });
    try {
      final data = await ApiService.getCourseOffers(
        onUnauthorized: () => context.read<AuthService>().logout(),
      );
      if (!mounted) return;
      if (data['ok'] != true) {
        setState(() { _error = data['error'] as String?; _loading = false; });
        return;
      }
      setState(() {
        _message = data['message'] as String?;
        _offers = ((data['offers'] as List?) ?? [])
            .map((o) => CourseOffer.fromJson(o as Map<String, dynamic>))
            .toList();
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() { _error = ApiService.friendlyError(e); _loading = false; });
    }
  }

  Future<void> _toggle(OfferSubject sub) async {
    if (_busy.contains(sub.offerSubjectId)) return;
    final action = sub.registered ? 'drop' : 'register';
    setState(() => _busy.add(sub.offerSubjectId));
    try {
      final data = await ApiService.registerCourse(
        offerSubjectId: sub.offerSubjectId,
        action: action,
        onUnauthorized: () => context.read<AuthService>().logout(),
      );
      if (!mounted) return;
      if (data['ok'] == true) {
        setState(() => sub.registered = data['registered'] == true);
        _snack(sub.registered ? 'Registered successfully.' : 'Registration dropped.');
      } else {
        _snack(data['error'] as String? ?? 'Could not update registration.', error: true);
      }
    } catch (e) {
      if (mounted) _snack(ApiService.friendlyError(e), error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(sub.offerSubjectId));
    }
  }

  void _snack(String msg, {bool error = false}) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: error ? AppTheme.error : AppTheme.success,
      behavior: SnackBarBehavior.floating,
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(title: const Text('Course Registration')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              color: AppTheme.primary,
              child: _buildBody(),
            ),
    );
  }

  Widget _buildBody() {
    if (_error != null) {
      return _centeredMessage(
        Icons.error_outline_rounded, AppTheme.error, _error!);
    }
    if (_offers.isEmpty) {
      return _centeredMessage(
        Icons.menu_book_outlined, AppTheme.textSecondary,
        _message ?? 'No courses have been offered for your batch yet.');
    }
    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
      children: _offers.map(_buildOfferCard).toList(),
    );
  }

  Widget _centeredMessage(IconData icon, Color color, String text) {
    return ListView(
      children: [
        const SizedBox(height: 80),
        Icon(icon, size: 56, color: color.withOpacity(0.6)),
        const SizedBox(height: 16),
        Center(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32),
            child: Text(text,
                textAlign: TextAlign.center,
                style: const TextStyle(color: AppTheme.textSecondary)),
          ),
        ),
      ],
    );
  }

  Widget _buildOfferCard(CourseOffer offer) {
    final subtitleParts = <String>[
      if ((offer.semester ?? '').isNotEmpty) offer.semester!,
      if ((offer.academicIntake ?? '').isNotEmpty) offer.academicIntake!,
    ];
    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            color: AppTheme.primary.withOpacity(0.06),
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        subtitleParts.isEmpty ? offer.programName : subtitleParts.join('  •  '),
                        style: const TextStyle(
                            fontWeight: FontWeight.bold,
                            color: AppTheme.primary),
                      ),
                    ),
                    _statusChip(offer.registrationOpen),
                  ],
                ),
                const SizedBox(height: 4),
                Text('${offer.deptName} › ${offer.programName}',
                    style: const TextStyle(
                        fontSize: 12, color: AppTheme.textSecondary)),
                const SizedBox(height: 2),
                Text(
                  '${offer.registeredCount}/${offer.totalSubjects} subject(s) registered',
                  style: const TextStyle(
                      fontSize: 12, color: AppTheme.textSecondary),
                ),
              ],
            ),
          ),
          ...offer.subjects.map((s) => _buildSubjectRow(offer, s)),
        ],
      ),
    );
  }

  Widget _statusChip(bool open) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: (open ? AppTheme.success : AppTheme.textSecondary).withOpacity(0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        open ? 'Open' : 'Closed',
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: open ? AppTheme.success : AppTheme.textSecondary,
        ),
      ),
    );
  }

  Widget _buildSubjectRow(CourseOffer offer, OfferSubject sub) {
    final busy = _busy.contains(sub.offerSubjectId);
    final teacherText = sub.teachers.map((t) => t.name).join(', ');
    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      [
                        if ((sub.courseCode ?? '').isNotEmpty) sub.courseCode!,
                        sub.courseName,
                      ].join('  '),
                      style: const TextStyle(
                          fontWeight: FontWeight.w600,
                          color: AppTheme.textPrimary),
                    ),
                    if ((sub.credit ?? '').isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text('Credit: ${sub.credit}',
                            style: const TextStyle(
                                fontSize: 12, color: AppTheme.textSecondary)),
                      ),
                    if (teacherText.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(teacherText,
                            style: const TextStyle(
                                fontSize: 12, color: AppTheme.textSecondary)),
                      ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              _buildActionButton(offer, sub, busy),
            ],
          ),
          const Divider(height: 18),
        ],
      ),
    );
  }

  Widget _buildActionButton(CourseOffer offer, OfferSubject sub, bool busy) {
    if (busy) {
      return const SizedBox(
        width: 24, height: 24,
        child: CircularProgressIndicator(strokeWidth: 2),
      );
    }
    if (!offer.registrationOpen) {
      return Chip(
        label: Text(sub.registered ? 'Registered' : '—',
            style: const TextStyle(fontSize: 12)),
        backgroundColor: sub.registered
            ? AppTheme.success.withOpacity(0.12)
            : AppTheme.divider,
        labelStyle: TextStyle(
            color: sub.registered ? AppTheme.success : AppTheme.textSecondary),
        visualDensity: VisualDensity.compact,
      );
    }
    return sub.registered
        ? OutlinedButton(
            onPressed: () => _toggle(sub),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppTheme.error,
              side: const BorderSide(color: AppTheme.error),
              padding: const EdgeInsets.symmetric(horizontal: 12),
            ),
            child: const Text('Drop'),
          )
        : ElevatedButton(
            onPressed: () => _toggle(sub),
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.primary,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 12),
            ),
            child: const Text('Register'),
          );
  }
}
