package bd.ac.primeuniversity.studentportal.ui.staff

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.StaffMeResponse
import bd.ac.primeuniversity.studentportal.databinding.FragmentStaffDashboardBinding
import bd.ac.primeuniversity.studentportal.ui.dashboard.DashboardMenuAdapter
import bd.ac.primeuniversity.studentportal.ui.dashboard.Feature
import bd.ac.primeuniversity.studentportal.ui.dashboard.buildStaffDashboardMenu
import bd.ac.primeuniversity.studentportal.ui.notifications.NotificationsActivity
import bd.ac.primeuniversity.studentportal.ui.settings.SettingsActivity
import bd.ac.primeuniversity.studentportal.ui.staff.attendance.StaffAttendanceActivity
import bd.ac.primeuniversity.studentportal.ui.staff.leave.LeaveActivity
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Staff home tab: header with the employee's name, type badge and a notices
 * bell (with unread badge), quick Today / Leave Balance cards and the
 * employee launcher menu (My Attendance, Leave Management, Notices, Profile).
 */
class StaffDashboardFragment : Fragment() {

    private var _binding: FragmentStaffDashboardBinding? = null
    private val binding get() = _binding!!

    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentStaffDashboardBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.menuList.layoutManager = LinearLayoutManager(requireContext())
        binding.menuList.adapter = DashboardMenuAdapter(buildStaffDashboardMenu()) { onFeature(it) }

        binding.btnBell.setOnClickListener {
            (activity as? StaffMainActivity)?.selectTab(R.id.nav_notices)
        }

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.info, R.color.cat_campus
        )
        binding.swipeRefresh.setOnRefreshListener { refreshSession() }

        app.currentStaff.observe(viewLifecycleOwner) { render(it) }
    }

    private fun onFeature(feature: Feature) {
        when (feature) {
            Feature.MY_ATTENDANCE ->
                startActivity(Intent(requireContext(), StaffAttendanceActivity::class.java))
            Feature.LEAVE_MANAGEMENT ->
                startActivity(Intent(requireContext(), LeaveActivity::class.java))
            Feature.STAFF_NOTICES ->
                (activity as? StaffMainActivity)?.selectTab(R.id.nav_notices)
            Feature.ANNOUNCEMENTS ->
                startActivity(Intent(requireContext(), NotificationsActivity::class.java))
            Feature.STAFF_PROFILE ->
                (activity as? StaffMainActivity)?.selectTab(R.id.nav_profile)
            Feature.SETTINGS -> openSettings()
            Feature.THEME -> openSettings(openTheme = true)
            else -> Unit
        }
    }

    private fun openSettings(openTheme: Boolean = false) {
        val intent = Intent(requireContext(), SettingsActivity::class.java)
            .putExtra(SettingsActivity.EXTRA_OPEN_THEME, openTheme)
        startActivity(intent)
    }

    private fun render(me: StaffMeResponse?) {
        binding.staffName.text = me?.user?.fullName?.takeIf { it.isNotBlank() }
            ?: getString(R.string.employee)

        val meta = listOfNotNull(
            me?.employee?.designation?.takeIf { it.isNotBlank() },
            me?.employee?.department?.takeIf { it.isNotBlank() },
        ).joinToString(" \u00b7 ")
        binding.staffMeta.text = meta
        binding.staffMeta.visibility = if (meta.isBlank()) View.GONE else View.VISIBLE

        val typeLabel = me?.employee?.employeeTypeLabel
        binding.staffTypeBadge.text = typeLabel ?: ""
        binding.staffTypeBadge.visibility =
            if (typeLabel.isNullOrBlank()) View.GONE else View.VISIBLE

        val notices = me?.stats?.noticesUniversity ?: 0
        binding.bellBadge.text = if (notices > 99) "99+" else notices.toString()
        binding.bellBadge.visibility = if (notices > 0) View.VISIBLE else View.GONE

        val dash = getString(R.string.dash)
        val today = me?.today
        binding.todayValue.text =
            "In ${today?.inTime ?: dash} \u00b7 Out ${today?.outTime ?: dash}"

        val lb = me?.leaveBalance
        binding.leaveValue.text = if (lb != null) {
            "Casual ${fmt(lb.casualRemaining)}/${fmt(lb.casualTotal)} \u00b7 " +
                "Sick ${fmt(lb.sickRemaining)}/${fmt(lb.sickTotal)}"
        } else {
            dash
        }
    }

    private fun fmt(v: Double): String =
        if (v % 1.0 == 0.0) v.toInt().toString() else v.toString()

    private fun refreshSession() {
        lifecycleScope.launch {
            when (val result = app.repository.staffMe()) {
                is AppResult.Success -> app.setStaffSession(result.data)
                is AppResult.Error -> Unit
            }
            _binding?.swipeRefresh?.isRefreshing = false
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
