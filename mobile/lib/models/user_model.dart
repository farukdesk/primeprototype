/// Represents an authenticated PUMIS admin user returned from the API.
class UserModel {
  final int id;
  final String fullName;
  final String username;
  final String email;
  final String group;
  final bool isSuper;
  final String? avatarUrl;
  final List<String> permissions;
  final Map<String, int> unread;

  const UserModel({
    required this.id,
    required this.fullName,
    required this.username,
    required this.email,
    required this.group,
    required this.isSuper,
    this.avatarUrl,
    required this.permissions,
    required this.unread,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) {
    final userMap = json['user'] as Map<String, dynamic>? ?? json;
    return UserModel(
      id: userMap['id'] as int? ?? 0,
      fullName: userMap['full_name'] as String? ?? '',
      username: userMap['username'] as String? ?? '',
      email: userMap['email'] as String? ?? '',
      group: userMap['group'] as String? ?? '',
      isSuper: userMap['is_super'] == true || userMap['is_super'] == 1,
      avatarUrl: userMap['avatar_url'] as String?,
      permissions: List<String>.from(json['permissions'] as List? ?? []),
      unread: Map<String, int>.from(
        (json['unread'] as Map?)?.map(
              (k, v) => MapEntry(k as String, (v as num?)?.toInt() ?? 0),
            ) ??
            {},
      ),
    );
  }

  bool hasPermission(String slug) => isSuper || permissions.contains(slug);

  @override
  String toString() => 'UserModel(id: $id, username: $username)';
}
