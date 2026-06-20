/// Financial summary and payment history for a student.
class FinanceSummary {
  final double totalDue;
  final double totalPaid;
  final double outstanding;
  final List<SemesterSummary> semesters;

  const FinanceSummary({
    required this.totalDue,
    required this.totalPaid,
    required this.outstanding,
    required this.semesters,
  });

  factory FinanceSummary.fromJson(Map<String, dynamic> j) {
    return FinanceSummary(
      totalDue:    (j['total_due']   as num).toDouble(),
      totalPaid:   (j['total_paid']  as num).toDouble(),
      outstanding: (j['outstanding'] as num).toDouble(),
      semesters: ((j['semesters'] as List?) ?? [])
          .map((s) => SemesterSummary.fromJson(s as Map<String, dynamic>))
          .toList(),
    );
  }
}

class SemesterSummary {
  final String label;
  final double totalDue;
  final double totalPaid;
  final double outstanding;

  const SemesterSummary({
    required this.label,
    required this.totalDue,
    required this.totalPaid,
    required this.outstanding,
  });

  factory SemesterSummary.fromJson(Map<String, dynamic> j) {
    return SemesterSummary(
      label:       j['label']       as String? ?? '',
      totalDue:   (j['total_due']   as num).toDouble(),
      totalPaid:  (j['total_paid']  as num).toDouble(),
      outstanding:(j['outstanding'] as num).toDouble(),
    );
  }
}

class Payment {
  final int    id;
  final String? voucherNumber;
  final String? date;
  final String? feeType;
  final int?    semester;
  final String? monthLabel;
  final double  amount;
  final String  method;
  final String  status;

  const Payment({
    required this.id,
    this.voucherNumber,
    this.date,
    this.feeType,
    this.semester,
    this.monthLabel,
    required this.amount,
    required this.method,
    required this.status,
  });

  factory Payment.fromJson(Map<String, dynamic> j) {
    return Payment(
      id:            (j['id']     as num).toInt(),
      voucherNumber:  j['voucher_number'] as String?,
      date:           j['date']           as String?,
      feeType:        j['fee_type']       as String?,
      semester:      (j['semester']       as num?)?.toInt(),
      monthLabel:     j['month_label']    as String?,
      amount:        (j['amount']         as num).toDouble(),
      method:         j['method']         as String? ?? 'Cash',
      status:         j['status']         as String? ?? 'paid',
    );
  }
}
