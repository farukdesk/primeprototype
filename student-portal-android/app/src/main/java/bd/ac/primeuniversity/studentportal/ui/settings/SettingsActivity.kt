package bd.ac.primeuniversity.studentportal.ui.settings

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivitySettingsBinding
import bd.ac.primeuniversity.studentportal.ui.login.LoginActivity
import kotlinx.coroutines.launch

/** Settings screen: account summary, privacy policy, about and sign out. */
class SettingsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySettingsBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }

        app.currentStudent.value?.let { student ->
            binding.avatar.text = student.initials
            binding.accountName.text = student.fullName
            binding.accountMeta.text = listOfNotNull(
                student.studentId, student.deptName
            ).joinToString(" · ")
        }

        binding.versionText.text = runCatching {
            packageManager.getPackageInfo(packageName, 0).versionName
        }.getOrNull() ?: ""

        binding.rowPrivacy.setOnClickListener { openPrivacyPolicy() }
        binding.btnSignOut.setOnClickListener { confirmSignOut() }
    }

    private fun openPrivacyPolicy() {
        runCatching {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(PrimeApp.PRIVACY_POLICY_URL)))
        }
    }

    private fun confirmSignOut() {
        AlertDialog.Builder(this)
            .setTitle(R.string.sign_out)
            .setMessage(R.string.sign_out_confirm)
            .setNegativeButton(R.string.cancel, null)
            .setPositiveButton(R.string.sign_out) { _, _ -> signOut() }
            .show()
    }

    private fun signOut() {
        lifecycleScope.launch {
            app.repository.logout()
            app.clearSession()
            val intent = Intent(this@SettingsActivity, LoginActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
            startActivity(intent)
            finishAffinity()
        }
    }
}
