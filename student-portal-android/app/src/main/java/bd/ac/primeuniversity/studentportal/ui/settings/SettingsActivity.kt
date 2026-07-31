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
import bd.ac.primeuniversity.studentportal.ui.feature.FeatureActivity
import bd.ac.primeuniversity.studentportal.ui.login.LoginActivity
import bd.ac.primeuniversity.studentportal.util.ThemePrefs
import kotlinx.coroutines.launch

/** Settings screen: account summary, appearance, privacy policy, about and sign out. */
class SettingsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySettingsBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }

        if (app.repository.isStaff) {
            app.currentStaff.value?.let { me ->
                binding.avatar.text = me.user?.initials ?: "?"
                binding.accountName.text = me.user?.fullName.orEmpty()
                binding.accountMeta.text = listOfNotNull(
                    me.employee?.designation, me.employee?.department
                ).joinToString(" · ")
            }
        } else {
            app.currentStudent.value?.let { student ->
                binding.avatar.text = student.initials
                binding.accountName.text = student.fullName
                binding.accountMeta.text = listOfNotNull(
                    student.studentId, student.deptName
                ).joinToString(" · ")
            }
        }

        binding.versionText.text = runCatching {
            packageManager.getPackageInfo(packageName, 0).versionName
        }.getOrNull() ?: ""

        updateThemeLabel()
        binding.rowTheme.setOnClickListener { showThemeDialog() }
        binding.rowPassword.setOnClickListener {
            if (app.repository.isStaff) {
                // Employees have a working change-password flow backed by
                // admin/api/staff/change-password.php.
                startActivity(Intent(this, ChangePasswordActivity::class.java))
            } else {
                startActivity(
                    FeatureActivity.open(
                        this,
                        R.string.feat_password_change,
                        R.drawable.ic_lock,
                        R.color.primary,
                    )
                )
            }
        }
        binding.rowPrivacy.setOnClickListener { openPrivacyPolicy() }
        binding.btnSignOut.setOnClickListener { confirmSignOut() }

        if (intent.getBooleanExtra(EXTRA_OPEN_THEME, false)) showThemeDialog()
    }

    private fun updateThemeLabel() {
        binding.themeValue.setText(
            when (ThemePrefs.getMode(this)) {
                ThemePrefs.MODE_LIGHT -> R.string.theme_light
                ThemePrefs.MODE_DARK -> R.string.theme_dark
                else -> R.string.theme_system
            }
        )
    }

    private fun showThemeDialog() {
        val labels = arrayOf(
            getString(R.string.theme_system),
            getString(R.string.theme_light),
            getString(R.string.theme_dark),
        )
        val current = when (ThemePrefs.getMode(this)) {
            ThemePrefs.MODE_LIGHT -> 1
            ThemePrefs.MODE_DARK -> 2
            else -> 0
        }
        AlertDialog.Builder(this)
            .setTitle(R.string.theme_dialog_title)
            .setSingleChoiceItems(labels, current) { dialog, which ->
                val mode = when (which) {
                    1 -> ThemePrefs.MODE_LIGHT
                    2 -> ThemePrefs.MODE_DARK
                    else -> ThemePrefs.MODE_SYSTEM
                }
                ThemePrefs.setMode(this, mode)
                updateThemeLabel()
                dialog.dismiss()
            }
            .setNegativeButton(R.string.cancel, null)
            .show()
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

    companion object {
        const val EXTRA_OPEN_THEME = "open_theme"
    }
}
