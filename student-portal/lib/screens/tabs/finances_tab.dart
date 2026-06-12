import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../models/finance_model.dart';
import '../../services/api_service.dart';
import '../../services/auth_service.dart';
import '../../theme/app_theme.dart';

class FinancesTab extends StatefulWidget {
  const FinancesTab({super.key});
  @override
  State<FinancesTab> createState() => _FinancesTabState();
}

class _FinancesTabState extends State<FinancesTab> {
  FinanceSummary? _summary;
  List<Payment>   _payments = [];
  bool   _loading = true;
  String? _error;
  String? _message;
  final _currency = NumberFormat('#,##0.00', 'en_US');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; _message = null; });
    try {
      final data = await ApiService.getFinances(
        onUnauthorized: () => context.read<AuthService>().logout(),
      );
      if (!mounted) return;
      if (data['ok'] != true) {
        setState(() { _error = data['error'] as String?; _loading = false; });
        return;
      }
      setState(() {
        _message  = data['message'] as String?;
        _summary  = data['summary'] != null
            ? FinanceSummary.fromJson(data['summary'] as Map<String, dynamic>)
            : null;
        _payments = ((data['payments'] as List?) ?? [])
            .map((p) => Payment.fromJson(p as Map<String, dynamic>))
            .toList();
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() { _error = ApiService.friendlyError(e); _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(title: const Text('My Finances')),
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
      return ListView(
        children: [
          const SizedBox(height: 60),
          Icon(Icons.error_outline_rounded,
              size: 56, color: AppTheme.error.withOpacity(0.6)),
          const SizedBox(height: 16),
          Center(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32),
              child: Text(_error!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AppTheme.textSecondary)),
            ),
          ),
        ],
      );
    }

    if (_message != null && _summary == null) {
      return ListView(
        children: [
          const SizedBox(height: 60),
          const Icon(Icons.account_balance_wallet_outlined,
              size: 56, color: Color(0xFFD1D5DB)),
          const SizedBox(height: 16),
          Center(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32),
              child: Text(_message!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AppTheme.textSecondary)),
            ),
          ),
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 80),
      children: [
        if (_summary != null) ...[
          _buildSummaryCard(_summary!),
          const SizedBox(height: 16),
          _buildSemesterCards(_summary!.semesters),
        ],
        if (_payments.isNotEmpty) ...[
          const SizedBox(height: 20),
          const _SectionHeader('Payment History'),
          const SizedBox(height: 12),
          ..._payments.map((p) => Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: _PaymentCard(payment: p, currency: _currency),
              )),
        ],
      ],
    );
  }

  Widget _buildSummaryCard(FinanceSummary s) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AppTheme.primaryDark, AppTheme.primary],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(
        children: [
          const Text('Fee Summary',
              style: TextStyle(color: Colors.white70, fontSize: 13)),
          const SizedBox(height: 16),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _SummaryPill(
                  label: 'Total Due',
                  value: '৳${_currency.format(s.totalDue)}',
                  color: Colors.white),
              _SummaryPill(
                  label: 'Paid',
                  value: '৳${_currency.format(s.totalPaid)}',
                  color: AppTheme.accent),
              _SummaryPill(
                  label: 'Outstanding',
                  value: '৳${_currency.format(s.outstanding)}',
                  color: s.outstanding > 0
                      ? const Color(0xFFFF6B6B)
                      : const Color(0xFF6EE7B7)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildSemesterCards(List<SemesterSummary> sems) {
    if (sems.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionHeader('Semester Breakdown'),
        const SizedBox(height: 12),
        ...sems.map((sem) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: _SemCard(semester: sem, currency: _currency),
            )),
      ],
    );
  }
}

class _SummaryPill extends StatelessWidget {
  final String label, value;
  final Color color;
  const _SummaryPill({required this.label, required this.value, required this.color});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(value,
            style: TextStyle(
                color: color,
                fontSize: 15,
                fontWeight: FontWeight.w700)),
        const SizedBox(height: 4),
        Text(label,
            style: const TextStyle(color: Colors.white60, fontSize: 10)),
      ],
    );
  }
}

class _SemCard extends StatelessWidget {
  final SemesterSummary semester;
  final NumberFormat    currency;
  const _SemCard({required this.semester, required this.currency});

  @override
  Widget build(BuildContext context) {
    final pct = semester.totalDue > 0
        ? (semester.totalPaid / semester.totalDue).clamp(0.0, 1.0)
        : 0.0;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [
          BoxShadow(color: Colors.black.withOpacity(0.04),
              blurRadius: 6, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(semester.label,
                  style: const TextStyle(
                      fontWeight: FontWeight.w600,
                      fontSize: 13,
                      color: AppTheme.textPrimary)),
              Text(
                semester.outstanding > 0
                    ? '৳${currency.format(semester.outstanding)} due'
                    : 'Cleared',
                style: TextStyle(
                  color: semester.outstanding > 0
                      ? AppTheme.error
                      : AppTheme.success,
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: pct,
              backgroundColor: const Color(0xFFE5E7EB),
              color: pct >= 1.0 ? AppTheme.success : AppTheme.primary,
              minHeight: 6,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            '৳${currency.format(semester.totalPaid)} of ৳${currency.format(semester.totalDue)} paid',
            style: const TextStyle(
                fontSize: 10, color: AppTheme.textSecondary),
          ),
        ],
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  final String text;
  const _SectionHeader(this.text);
  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: const TextStyle(
          fontSize: 15,
          fontWeight: FontWeight.w700,
          color: AppTheme.textPrimary),
    );
  }
}

class _PaymentCard extends StatelessWidget {
  final Payment      payment;
  final NumberFormat currency;
  const _PaymentCard({required this.payment, required this.currency});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [
          BoxShadow(color: Colors.black.withOpacity(0.04),
              blurRadius: 6, offset: const Offset(0, 2)),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppTheme.success.withOpacity(0.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.receipt_long_rounded,
                color: AppTheme.success, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  payment.feeType ?? 'Fee Payment',
                  style: const TextStyle(
                      fontWeight: FontWeight.w600,
                      fontSize: 13,
                      color: AppTheme.textPrimary),
                ),
                const SizedBox(height: 2),
                Text(
                  [
                    if (payment.voucherNumber != null)
                      'V#${payment.voucherNumber}',
                    if (payment.date != null) payment.date!,
                    payment.method,
                  ].join(' · '),
                  style: const TextStyle(
                      fontSize: 11, color: AppTheme.textSecondary),
                ),
              ],
            ),
          ),
          Text(
            '৳${currency.format(payment.amount)}',
            style: const TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: AppTheme.success),
          ),
        ],
      ),
    );
  }
}
