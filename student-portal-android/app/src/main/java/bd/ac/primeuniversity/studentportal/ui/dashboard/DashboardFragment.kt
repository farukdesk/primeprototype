package bd.ac.primeuniversity.studentportal.ui.dashboard

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.core.graphics.ColorUtils
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.Stats
import bd.ac.primeuniversity.studentportal.data.model.Student
import bd.ac.primeuniversity.studentportal.databinding.FragmentDashboardBinding
import bd.ac.primeuniversity.studentportal.ui.courses.RegisteredCoursesActivity
import bd.ac.primeuniversity.studentportal.ui.feature.FeatureActivity
import bd.ac.primeuniversity.studentportal.ui.idcard.IdCardActivity
import bd.ac.primeuniversity.studentportal.ui.main.MainActivity
import bd.ac.primeuniversity.studentportal.ui.notifications.NotificationsActivity
import bd.ac.primeuniversity.studentportal.ui.settings.SettingsActivity
import bd.ac.primeuniversity.studentportal.util.AppResult
import bd.ac.primeuniversity.studentportal.util.Formatters
import kotlinx.coroutines.launch

/**
 * Home tab: welcome header, quick stats and a grouped launcher menu giving
 * access to every student-portal feature (Academic, Examination, Campus,
 * Profile, Finances and Settings).
 */
class DashboardFragment : Fragment() {

    private var _binding: FragmentDashboardBinding? = null
    private val binding get() = _binding!!

    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentDashboardBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.menuList.layoutManager = LinearLayoutManager(requireContext())
        binding.menuList.adapter = DashboardMenuAdapter(buildDashboardMenu()) { onFeature(it) }

        binding.btnSettings.setOnClickListener { openSettings() }

        // Colourful pull-to-refresh spinner.
        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.info, R.color.cat_campus
        )

        // Gentle entrance animation for the header stat cards.
        val enter = android.view.animation.AnimationUtils.loadAnimation(
            requireContext(), R.anim.fade_in_up
        )
        binding.statNotices.root.startAnimation(enter)
        binding.statOutstanding.root.startAnimation(
            android.view.animation.AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in_up)
                .also { it.startOffset = 80 }
        )

        // Stat cards static labels/icons
        binding.statNotices.statLabel.text = getString(R.string.stat_notices)
        binding.statNotices.statIcon.setImageResource(R.drawable.ic_notifications)
        binding.statOutstanding.statLabel.text = getString(R.string.stat_outstanding)
        binding.statOutstanding.statIcon.setImageResource(R.drawable.ic_wallet)

        app.currentStudent.observe(viewLifecycleOwner) { renderStudent(it) }
        app.currentStats.observe(viewLifecycleOwner) { renderStats(it) }

        binding.swipeRefresh.setOnRefreshListener { refreshSession() }
    }

    private fun onFeature(feature: Feature) {
        val activity = activity as? MainActivity
        when (feature) {
            Feature.REGISTERED_COURSES ->
                startActivity(Intent(requireContext(), RegisteredCoursesActivity::class.java))
            Feature.NOTICES -> activity?.selectTab(R.id.nav_notices)
            Feature.ANNOUNCEMENTS ->
                startActivity(Intent(requireContext(), NotificationsActivity::class.java))
            Feature.DUE_TODAY,
            Feature.TOTAL_PAID,
            Feature.TRANSACTION_HISTORY -> activity?.selectTab(R.id.nav_finances)
            Feature.STUDENT_PROFILE -> activity?.selectTab(R.id.nav_profile)
            Feature.SETTINGS -> openSettings()
            Feature.THEME -> openSettings(openTheme = true)
            Feature.ID_CARD -> startActivity(Intent(requireContext(), IdCardActivity::class.java))
            else -> startActivity(FeatureActivity.intent(requireContext(), feature))
        }
    }

    private fun openSettings(openTheme: Boolean = false) {
        val intent = Intent(requireContext(), SettingsActivity::class.java)
            .putExtra(SettingsActivity.EXTRA_OPEN_THEME, openTheme)
        startActivity(intent)
    }

    private fun renderStudent(student: Student?) {
        binding.studentName.text = student?.fullName?.takeIf { it.isNotBlank() }
            ?: getString(R.string.student)
        val meta = listOfNotNull(
            student?.studentId?.takeIf { it.isNotBlank() },
            student?.deptCode?.takeIf { it.isNotBlank() } ?: student?.deptName,
        ).joinToString(" · ")
        binding.studentMeta.text = meta
        binding.studentMeta.visibility = if (meta.isBlank()) View.GONE else View.VISIBLE
    }

    private fun renderStats(stats: Stats?) {
        val notices = stats?.noticesUniversity ?: 0
        tint(binding.statNotices.iconContainer, R.color.info)
        binding.statNotices.statValue.text = notices.toString()
        binding.statNotices.statValue.setTextColor(color(R.color.info))

        val outstanding = stats?.outstandingBalance
        val positive = outstanding != null && outstanding > 0
        val colorRes = if (positive) R.color.error else R.color.success
        tint(binding.statOutstanding.iconContainer, colorRes)
        binding.statOutstanding.statValue.setTextColor(color(colorRes))
        binding.statOutstanding.statValue.text =
            if (outstanding != null) Formatters.moneyWhole(outstanding) else getString(R.string.dash)
    }

    private fun refreshSession() {
        lifecycleScope.launch {
            when (val result = app.repository.me()) {
                is AppResult.Success ->
                    app.setSession(result.data.student, result.data.stats)
                is AppResult.Error -> Unit
            }
            _binding?.swipeRefresh?.isRefreshing = false
        }
    }

    private fun tint(view: View, colorRes: Int) {
        val base = color(colorRes)
        view.background?.mutate()?.setTint(ColorUtils.setAlphaComponent(base, 40))
    }

    private fun color(res: Int) = ContextCompat.getColor(requireContext(), res)

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
