package bd.ac.primeuniversity.pumisadmin

import io.flutter.embedding.android.FlutterFragmentActivity

/**
 * PUMIS Admin – Main Activity
 *
 * Uses FlutterFragmentActivity instead of FlutterActivity so that
 * the local_auth (biometric) plugin works correctly on all Android versions.
 */
class MainActivity : FlutterFragmentActivity()
