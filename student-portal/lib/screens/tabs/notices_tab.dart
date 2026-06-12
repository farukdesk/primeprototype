import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../models/notice_model.dart';
import '../../services/api_service.dart';
import '../../services/auth_service.dart';
import '../../theme/app_theme.dart';

/// Notices tab with University / Department segmented control.
class NoticesTab extends StatefulWidget {
  const NoticesTab({super.key});
  @override
  State<NoticesTab> createState() => _NoticesTabState();
}

class _NoticesTabState extends State<NoticesTab> {
  int _segment = 0; // 0 = university, 1 = department

  // University
  final List<NoticeModel> _uni   = [];
  bool _uniLoading = true;
  bool _uniHasMore = true;
  int  _uniPage    = 1;

  // Department
  final List<NoticeModel> _dept   = [];
  bool _deptLoading = true;
  bool _deptHasMore = true;
  int  _deptPage    = 1;
  String _deptName  = 'Department';

  late ScrollController _scrollCtrl;

  @override
  void initState() {
    super.initState();
    _scrollCtrl = ScrollController()..addListener(_onScroll);
    _fetchPage(type: 'university', page: 1);
    _fetchPage(type: 'department', page: 1);
  }

  @override
  void dispose() {
    _scrollCtrl.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollCtrl.position.pixels >=
        _scrollCtrl.position.maxScrollExtent - 200) {
      _loadMore();
    }
  }

  Future<void> _fetchPage({required String type, required int page}) async {
    final onUnauth = () => context.read<AuthService>().logout();
    try {
      final data = await ApiService.getNotices(
          type: type, page: page, onUnauthorized: onUnauth);
      if (!mounted) return;
      if (data['ok'] == true) {
        final raw  = (data['notices'] as List?) ?? [];
        final list = raw.map((n) => NoticeModel.fromJson(n as Map<String, dynamic>)).toList();
        final total = (data['total'] as num?)?.toInt() ?? 0;
        setState(() {
          if (type == 'university') {
            if (page == 1) _uni.clear();
            _uni.addAll(list);
            _uniHasMore = _uni.length < total;
            _uniLoading = false;
          } else {
            _deptName = (data['dept_name'] as String?) ?? 'Department';
            if (page == 1) _dept.clear();
            _dept.addAll(list);
            _deptHasMore = _dept.length < total;
            _deptLoading = false;
          }
        });
      }
    } catch (_) {
      if (!mounted) return;
      setState(() {
        if (type == 'university') _uniLoading  = false;
        else                      _deptLoading = false;
      });
    }
  }

  Future<void> _refresh() async {
    setState(() {
      if (_segment == 0) { _uniPage = 1;  _uniLoading  = true; _uniHasMore  = true; }
      else               { _deptPage = 1; _deptLoading = true; _deptHasMore = true; }
    });
    await _fetchPage(
        type: _segment == 0 ? 'university' : 'department', page: 1);
  }

  void _loadMore() {
    if (_segment == 0 && !_uniLoading && _uniHasMore) {
      _uniPage++;
      setState(() => _uniLoading = true);
      _fetchPage(type: 'university', page: _uniPage);
    } else if (_segment == 1 && !_deptLoading && _deptHasMore) {
      _deptPage++;
      setState(() => _deptLoading = true);
      _fetchPage(type: 'department', page: _deptPage);
    }
  }

  @override
  Widget build(BuildContext context) {
    final notices = _segment == 0 ? _uni   : _dept;
    final loading = _segment == 0 ? _uniLoading : _deptLoading;

    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(
        title: const Text('Notices'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(52),
          child: _buildSegment(),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        color: AppTheme.primary,
        child: notices.isEmpty && loading
            ? const Center(child: CircularProgressIndicator())
            : notices.isEmpty
                ? ListView(
                    children: [
                      SizedBox(height: MediaQuery.of(context).size.height * 0.3),
                      const Icon(Icons.notifications_none_rounded,
                          size: 64,
                          color: Color(0xFFD1D5DB)),
                      const SizedBox(height: 16),
                      const Center(
                        child: Text(
                          'No notices yet.',
                          style: TextStyle(
                              color: AppTheme.textSecondary, fontSize: 14),
                        ),
                      ),
                    ],
                  )
                : ListView.builder(
                    controller: _scrollCtrl,
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 80),
                    itemCount: notices.length + (loading ? 1 : 0),
                    itemBuilder: (_, i) {
                      if (i == notices.length) {
                        return const Padding(
                          padding: EdgeInsets.all(16),
                          child: Center(child: CircularProgressIndicator()),
                        );
                      }
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: _NoticeListCard(
                          notice: notices[i],
                          onTap: () => context.push(
                            '/notice-detail',
                            extra: {'id': notices[i].id, 'type': notices[i].type, 'notice': notices[i]},
                          ),
                        ),
                      );
                    },
                  ),
      ),
    );
  }

  Widget _buildSegment() {
    return Container(
      color: AppTheme.primary,
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Container(
        height: 40,
        decoration: BoxDecoration(
          color: Colors.white.withOpacity(0.15),
          borderRadius: BorderRadius.circular(24),
        ),
        child: Row(
          children: [
            _SegBtn(label: 'University', selected: _segment == 0,
                onTap: () => setState(() => _segment = 0)),
            _SegBtn(label: _deptName,    selected: _segment == 1,
                onTap: () => setState(() => _segment = 1)),
          ],
        ),
      ),
    );
  }
}

class _SegBtn extends StatelessWidget {
  final String   label;
  final bool     selected;
  final VoidCallback onTap;
  const _SegBtn({required this.label, required this.selected, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: GestureDetector(
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          decoration: BoxDecoration(
            color: selected ? Colors.white : Colors.transparent,
            borderRadius: BorderRadius.circular(22),
          ),
          alignment: Alignment.center,
          child: Text(
            label,
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: selected ? AppTheme.primary : Colors.white70,
            ),
          ),
        ),
      ),
    );
  }
}

class _NoticeListCard extends StatelessWidget {
  final NoticeModel notice;
  final VoidCallback onTap;
  const _NoticeListCard({required this.notice, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final accent = notice.isDepartment ? AppTheme.success : AppTheme.primary;
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(14),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(
            border: Border(left: BorderSide(color: accent, width: 4)),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 8, vertical: 2),
                          decoration: BoxDecoration(
                            color: accent.withOpacity(0.1),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(
                            notice.isDepartment
                                ? (notice.deptName ?? 'Dept')
                                : 'University',
                            style: TextStyle(
                                color: accent,
                                fontSize: 10,
                                fontWeight: FontWeight.w600),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text(notice.date,
                            style: const TextStyle(
                                fontSize: 10,
                                color: AppTheme.textSecondary)),
                        if (notice.hasAttachment) ...[
                          const SizedBox(width: 8),
                          const Icon(Icons.attach_file_rounded,
                              size: 12, color: AppTheme.textSecondary),
                        ],
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(
                      notice.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                        color: AppTheme.textPrimary,
                        height: 1.4,
                      ),
                    ),
                    if (notice.content != null &&
                        notice.content!.trim().isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        notice.content!
                            .replaceAll(RegExp(r'<[^>]*>'), '')
                            .trim(),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontSize: 12,
                            color: AppTheme.textSecondary,
                            height: 1.5),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 8),
              const Icon(Icons.chevron_right_rounded,
                  color: AppTheme.textSecondary, size: 20),
            ],
          ),
        ),
      ),
    );
  }
}
