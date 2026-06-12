/// A university or department notice.
class NoticeModel {
  final int    id;
  final String type;        // 'university' | 'department'
  final String title;
  final String? content;
  final String  contentType; // 'html' | 'text'
  final String  date;        // ISO date string YYYY-MM-DD
  final String? deptName;
  final String? attachmentUrl;
  final String? attachmentName;
  final int?    attachmentSizeKb;

  const NoticeModel({
    required this.id,
    required this.type,
    required this.title,
    this.content,
    required this.contentType,
    required this.date,
    this.deptName,
    this.attachmentUrl,
    this.attachmentName,
    this.attachmentSizeKb,
  });

  factory NoticeModel.fromJson(Map<String, dynamic> j) {
    return NoticeModel(
      id:              (j['id'] as num).toInt(),
      type:             j['type']         as String? ?? 'university',
      title:            j['title']        as String,
      content:          j['content']      as String?,
      contentType:      j['content_type'] as String? ?? 'text',
      date:             j['date']         as String? ?? '',
      deptName:         j['dept_name']    as String?,
      attachmentUrl:    j['attachment_url']      as String?,
      attachmentName:   j['attachment_name']     as String?,
      attachmentSizeKb:(j['attachment_size_kb']  as num?)?.toInt(),
    );
  }

  bool get hasAttachment => attachmentUrl != null && attachmentUrl!.isNotEmpty;
  bool get isDepartment  => type == 'department';
}
