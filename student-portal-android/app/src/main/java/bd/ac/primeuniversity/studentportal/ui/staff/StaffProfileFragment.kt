package bd.ac.primeuniversity.studentportal.ui.staff

import android.os.Bundle
import android.util.TypedValue
import android.view.Gravity
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.fragment.app.Fragment
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.StaffMeResponse
import bd.ac.primeuniversity.studentportal.databinding.FragmentStaffProfileBinding

/** Staff profile tab: employee details and logout. */
class StaffProfileFragment : Fragment() {

    private var _binding: FragmentStaffProfileBinding? = null
    private val binding get() = _binding!!

    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentStaffProfileBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.btnLogout.setOnClickListener { confirmLogout() }
        app.currentStaff.observe(viewLifecycleOwner) { render(it) }
    }

    private fun render(me: StaffMeResponse?) {
        val user = me?.user
        binding.avatar.text = user?.initials ?: "?"
        binding.name.text = user?.fullName?.takeIf { it.isNotBlank() }
            ?: getString(R.string.employee)

        val meta = listOfNotNull(
            user?.group?.takeIf { it.isNotBlank() },
            user?.username?.takeIf { it.isNotBlank() },
        ).joinToString(" \u00b7 ")
        binding.meta.text = meta
        binding.meta.visibility = if (meta.isBlank()) View.GONE else View.VISIBLE

        val typeLabel = me?.employee?.employeeTypeLabel
        binding.typeBadge.text = typeLabel ?: ""
        binding.typeBadge.visibility =
            if (typeLabel.isNullOrBlank()) View.GONE else View.VISIBLE

        binding.rows.removeAllViews()
        val e = me?.employee
        addRow(getString(R.string.lbl_employee_id), e?.employeeId)
        addRow(getString(R.string.lbl_designation), e?.designation)
        addRow(getString(R.string.lbl_department), e?.department)
        addRow(getString(R.string.lbl_phone), e?.phone)
        addRow(getString(R.string.lbl_email), user?.email)
        addRow(getString(R.string.lbl_blood_group), e?.bloodGroup)
        addRow(getString(R.string.lbl_job_type), e?.jobType)
        addRow(getString(R.string.lbl_joining_date), e?.joiningDate)
        addRow(getString(R.string.lbl_status), e?.employeeStatus)
    }

    private fun addRow(label: String, value: String?) {
        if (value.isNullOrBlank()) return
        val ctx = requireContext()

        val secondary = TypedValue().let { tv ->
            ctx.theme.resolveAttribute(android.R.attr.textColorSecondary, tv, true)
            androidx.core.content.ContextCompat.getColorStateList(ctx, tv.resourceId)
        }

        val row = LinearLayout(ctx).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(dp(16), dp(10), dp(16), dp(10))
            gravity = Gravity.CENTER_VERTICAL
        }
        row.addView(TextView(ctx).apply {
            text = label
            textSize = 14f
            secondary?.let { setTextColor(it) }
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f)
        })
        row.addView(TextView(ctx).apply {
            text = value
            textSize = 14f
            gravity = Gravity.END
            setTypeface(typeface, android.graphics.Typeface.BOLD)
        })
        binding.rows.addView(row)
    }

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).toInt()

    private fun confirmLogout() {
        AlertDialog.Builder(requireContext())
            .setTitle(R.string.staff_logout)
            .setMessage(R.string.staff_logout_confirm)
            .setPositiveButton(R.string.staff_logout) { _, _ ->
                (activity as? StaffMainActivity)?.logout()
            }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
